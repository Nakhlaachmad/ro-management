<?php

declare(strict_types=1);

namespace App\Providers;

use App\Infrastructure\FP\DocumentStorage;
use App\Infrastructure\FP\NextcloudStorage;
use App\Infrastructure\FP\PrivateLocalStorage;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;

final class FpServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(DocumentStorage::class, function () {
            if (config('fp.storage_adapter') === 'nextcloud') {
                return new NextcloudStorage(app(\Illuminate\Http\Client\Factory::class), (string) config('fp.nextcloud.endpoint'), (string) config('fp.nextcloud.user'), (string) config('fp.nextcloud.password'));
            }
            if (config('fp.storage_adapter') !== 'local') {
                throw new \RuntimeException('Private storage adapter required');
            }

            return new PrivateLocalStorage(Storage::build(['driver' => 'local', 'root' => storage_path('app/fp-private'), 'visibility' => 'private', 'throw' => true]));
        });
    }

    public function boot(): void
    {
        if (! \App\Application\FP\FeatureAccess::enabled()) {
            return;
        }
        \Livewire\Livewire::component('fp-submission-updates', \App\Filament\FP\SubmissionUpdates::class);
        foreach ([\App\Models\FpSubmission::class, \App\Models\FpMembership::class, \App\Models\FpTeam::class, \App\Models\FpDocument::class] as $model) {
            Gate::policy($model, \App\Policies\FpPolicy::class);
        }
        RateLimiter::for('fp-form', fn ($r) => Limit::perMinute(30)->by($r->ip()));
        RateLimiter::for('fp-submit', fn ($r) => Limit::perMinute(5)->by($r->ip()));
        RateLimiter::for('fp-report-export', fn ($r) => Limit::perMinute(5)->by((string) ($r->user()?->id ?? $r->ip())));
        $this->loadRoutesFrom(base_path('routes/fp.php'));
    }
}
