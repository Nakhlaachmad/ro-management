<?php

namespace Tests\Feature;

use App\Http\Middleware\VerifyCsrfToken;
use Filament\Facades\Filament;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken as FrameworkCsrf;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Session\TokenMismatchException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

#[Group('u5-csrf')]
class LegacyCsrfCharacterizationTest extends TestCase
{
    public static function requests(): array
    {
        $cases = [];
        foreach (['web', 'panel'] as $surface) {
            foreach ([
                'valid' => ['valid', null, true],
                'missing' => [null, null, false],
                'invalid' => ['invalid', null, false],
                'same-origin missing' => [null, 'same-origin', false],
                'same-origin invalid' => ['invalid', 'same-origin', false],
                'same-origin valid' => ['valid', 'same-origin', true],
            ] as $name => $case) {
                $cases[$surface.' '.$name] = [$surface, ...$case];
            }
        }

        return $cases;
    }

    #[DataProvider('requests')]
    public function test_state_changing_requests_require_the_session_token(string $surface, ?string $token, ?string $origin, bool $accepted): void
    {
        // Bootstrap remains isolated; only the runtime CSRF testing bypass is disabled.
        $this->app['env'] = 'local';
        $this->assertFalse($this->app->runningUnitTests());
        $middleware = $this->middleware($surface);
        $request = $this->request('POST', $surface, $token, $origin);

        if (! $accepted) {
            $this->expectException(TokenMismatchException::class);
        }

        $response = $middleware->handle($request, fn () => new Response('accepted'));
        $this->assertSame('accepted', $response->getContent());
        $cookies = $response->headers->getCookies();
        $this->assertCount(1, $cookies);
        $this->assertSame('XSRF-TOKEN', $cookies[0]->getName());
        $this->assertSame('legacy-session-token', $cookies[0]->getValue());
    }

    public function test_reading_requests_and_current_exemption_configuration(): void
    {
        $this->app['env'] = 'local';
        foreach (['web', 'panel'] as $surface) {
            $middleware = $this->middleware($surface);
            $except = (new \ReflectionProperty($middleware, 'except'))->getValue($middleware);
            $this->assertSame([], $except, 'No explicit legacy exemptions exist to characterize.');
            $response = $middleware->handle($this->request('GET', $surface, null, null), fn () => new Response('reading'));
            $this->assertSame('reading', $response->getContent());
        }
    }

    private function middleware(string $surface): FrameworkCsrf
    {
        $class = VerifyCsrfToken::class;
        if ($surface === 'panel') {
            $classes = array_values(array_filter(Filament::getPanel('admin')->getMiddleware(),
                fn ($class) => is_string($class) && is_a($class, FrameworkCsrf::class, true)));
            $this->assertCount(1, $classes);
            $class = $classes[0];
        }

        return $this->app->make($class);
    }

    private function request(string $method, string $surface, ?string $token, ?string $origin): Request
    {
        $session = new Store('csrf-characterization', new ArraySessionHandler(120));
        $session->start();
        $session->put('_token', 'legacy-session-token');
        $request = Request::create($surface === 'panel' ? '/livewire/update' : '/local-csrf-probe', $method,
            $token === null ? [] : ['_token' => $token === 'valid' ? 'legacy-session-token' : 'wrong-token']);
        $request->setLaravelSession($session);
        if ($origin !== null) {
            $request->headers->set('Sec-Fetch-Site', $origin);
        }

        return $request;
    }
}
