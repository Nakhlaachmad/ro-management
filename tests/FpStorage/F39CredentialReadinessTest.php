<?php

declare(strict_types=1);

namespace Tests\FpStorage;

use App\Filament\Pages\FP\DocumentStorageStatus;
use App\Infrastructure\FP\NextcloudWebDavClient;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Http\Client\Factory;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use RuntimeException;

final class F39CredentialReadinessTest extends TestCase
{
    private const UUID = '00000000-0000-4000-8000-000000000001';

    private const COLLECTION = '<d:multistatus xmlns:d="DAV:"><d:response><d:propstat><d:prop><d:resourcetype><d:collection/></d:resourcetype></d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response></d:multistatus>';

    private Container $previousContainer;

    private Factory $http;

    protected function setUp(): void
    {
        $this->previousContainer = Container::getInstance();
        $this->http = new Factory;
        $this->http->preventStrayRequests();
        $container = new Container;
        $container->instance(Factory::class, $this->http);
        $container->instance('config', new Repository(['nextcloud' => [
            'enabled' => false,
            'base_url' => 'https://storage.invalid',
            'username' => 'fixture-user-sentinel',
            'app_password' => 'fixture-password-sentinel',
            'root_folder' => 'F39-Credential-Readiness',
        ]]));
        Container::setInstance($container);
    }

    protected function tearDown(): void
    {
        Container::setInstance($this->previousContainer);
    }

    private function assertSentinelsAbsent(string $value, array $sentinels): void
    {
        foreach ($sentinels as $sentinel) {
            self::assertFalse(str_contains($value, $sentinel), 'Synthetic sensitive value appeared in a public diagnostic.');
        }
    }

    private function assertTraceArgumentsProtected(RuntimeException $error, array $sentinels): void
    {
        foreach ($error->getTrace() as $frame) {
            foreach ($frame['args'] ?? [] as $argument) {
                if (is_string($argument)) {
                    $this->assertSentinelsAbsent($argument, $sentinels);
                }
            }
        }
    }

    public function test_application_autoload_uses_this_isolated_worktree(): void
    {
        $expectedRoot = dirname(__DIR__, 2);
        $resolved = (new ReflectionClass(NextcloudWebDavClient::class))->getFileName();
        self::assertStringStartsWith(str_replace('\\', '/', $expectedRoot).'/', str_replace('\\', '/', $resolved));
    }

    public function test_missing_credentials_fail_before_any_request_with_flag_off(): void
    {
        foreach (['username', 'app_password'] as $missing) {
            $original = config('nextcloud.'.$missing);
            config(['nextcloud.'.$missing => '']);
            try {
                NextcloudWebDavClient::configured(healthCheckOnly: true);
                self::fail('Incomplete credential configuration accepted.');
            } catch (RuntimeException $error) {
                self::assertSame('NEXTCLOUD_CONFIGURATION_INVALID', $error->getMessage());
                self::assertNull($error->getPrevious());
            } finally {
                config(['nextcloud.'.$missing => $original]);
            }
        }
        self::assertFalse(config('nextcloud.enabled'));
        $this->http->assertNothingSent();
    }

    public function test_disabled_write_client_cannot_be_resolved(): void
    {
        try {
            NextcloudWebDavClient::configured();
            self::fail('Disabled write client was resolved.');
        } catch (RuntimeException $error) {
            self::assertSame('NEXTCLOUD_DISABLED', $error->getMessage());
        }
        $this->http->assertNothingSent();
    }

    public function test_explicit_health_check_works_without_enabling_writes(): void
    {
        $this->http->fake(function ($request, $options) {
            self::assertSame('PROPFIND', $request->method());
            self::assertTrue($options['verify']);
            self::assertFalse($options['allow_redirects']);

            return Factory::response(self::COLLECTION, 207);
        });
        $client = NextcloudWebDavClient::configured(healthCheckOnly: true);
        self::assertSame('CONNECTED', $client->health());
        try {
            $client->put('staging/'.self::UUID.'/document.bin', 'fixture-document-sentinel');
            self::fail('Health-only client uploaded.');
        } catch (RuntimeException $error) {
            self::assertSame('NEXTCLOUD_DISABLED', $error->getMessage());
        }
        self::assertFalse(config('nextcloud.enabled'));
        $this->http->assertSentCount(1);
    }

    public function test_upstream_authentication_body_is_not_an_exception_diagnostic(): void
    {
        $this->http->fake(['*' => Factory::response('fixture-user-sentinel fixture-password-sentinel', 401)]);
        try {
            NextcloudWebDavClient::configured(healthCheckOnly: true)->health();
            self::fail('Authentication failure accepted.');
        } catch (RuntimeException $error) {
            self::assertSame('NEXTCLOUD_AUTHENTICATION_FAILED', $error->getMessage());
            self::assertNull($error->getPrevious());
            $this->assertSentinelsAbsent($error->getMessage(), ['fixture-user-sentinel', 'fixture-password-sentinel']);
        }
        $this->http->assertSentCount(1);
    }

    public function test_transport_exception_chain_is_removed(): void
    {
        $attempts = 0;
        $this->http->fake(function () use (&$attempts) {
            $attempts++;
            throw new RuntimeException('fixture-user-sentinel fixture-password-sentinel');
        });
        try {
            NextcloudWebDavClient::configured(healthCheckOnly: true)->health();
            self::fail('Upstream transport failure accepted.');
        } catch (RuntimeException $error) {
            self::assertSame('NEXTCLOUD_UNAVAILABLE', $error->getMessage());
            self::assertNull($error->getPrevious());
            $this->assertSentinelsAbsent($error->getMessage(), ['fixture-user-sentinel', 'fixture-password-sentinel']);
        }
        self::assertSame(1, $attempts);
    }

    public function test_invalid_configuration_trace_protects_both_credential_arguments(): void
    {
        $previous = ini_set('zend.exception_ignore_args', '0');
        try {
            new NextcloudWebDavClient($this->http, 'http://storage.invalid', 'fixture-user-sentinel', 'fixture-password-sentinel');
            self::fail('HTTP configuration accepted.');
        } catch (RuntimeException $error) {
            self::assertSame('NEXTCLOUD_CONFIGURATION_INVALID', $error->getMessage());
            $this->assertTraceArgumentsProtected($error, ['fixture-user-sentinel', 'fixture-password-sentinel']);
        } finally {
            ini_set('zend.exception_ignore_args', $previous);
        }
        $this->http->assertNothingSent();
    }

    public function test_upload_failure_trace_protects_document_bytes(): void
    {
        $this->http->fake(fn ($request) => $request->method() === 'MKCOL'
            ? Factory::response('', 201)
            : throw new RuntimeException('fixture-upstream-sentinel'));
        $client = new NextcloudWebDavClient($this->http, 'https://storage.invalid', 'fixture-user-sentinel', 'fixture-password-sentinel');
        $previous = ini_set('zend.exception_ignore_args', '0');
        try {
            $client->put('staging/'.self::UUID.'/document.bin', 'fixture-document-sentinel');
            self::fail('Upload failure accepted.');
        } catch (RuntimeException $error) {
            self::assertSame('NEXTCLOUD_UPLOAD_UNCERTAIN', $error->getMessage());
            self::assertNull($error->getPrevious());
            $this->assertTraceArgumentsProtected($error, ['fixture-document-sentinel', 'fixture-password-sentinel']);
        } finally {
            ini_set('zend.exception_ignore_args', $previous);
        }
    }

    public function test_health_page_public_properties_have_no_credential_fields(): void
    {
        $properties = (new ReflectionClass(DocumentStorageStatus::class))->getProperties(ReflectionProperty::IS_PUBLIC);
        $names = array_map(fn ($property) => $property->getName(), $properties);
        foreach (['username', 'password', 'app_password', 'client', 'credentials', 'config'] as $forbidden) {
            self::assertNotContains($forbidden, $names);
        }
    }

    public function test_environment_template_documents_nextcloud_settings_without_secrets(): void
    {
        $template = file_get_contents(dirname(__DIR__, 2).'/.env.example');
        self::assertIsString($template);

        foreach (['NEXTCLOUD_ENABLED', 'NEXTCLOUD_BASE_URL', 'NEXTCLOUD_USERNAME', 'NEXTCLOUD_APP_PASSWORD', 'NEXTCLOUD_ROOT_FOLDER'] as $key) {
            self::assertMatchesRegularExpression('/^'.preg_quote($key, '/').'=/m', $template);
        }
        self::assertMatchesRegularExpression('/^NEXTCLOUD_ENABLED=false$/m', $template);
        self::assertDoesNotMatchRegularExpression('/^NEXTCLOUD_APP_PASSWORD=.+$/m', $template);
    }
}
