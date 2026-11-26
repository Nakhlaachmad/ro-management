<?php

declare(strict_types=1);

namespace Tests\Feature;

use Filament\Panel;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

final class DesignSystemTest extends TestCase
{
    public function test_stat_component_renders_accessible_link_and_escaped_content(): void
    {
        $html = Blade::render('<x-fp.ui.stat-card :label="$label" :value="$value" description="Semua membership" href="/fp-dev/fp-memberships" />', ['label' => '<script>unsafe</script>', 'value' => 1234]);
        self::assertStringContainsString('1,234', $html);
        self::assertStringContainsString('href="/fp-dev/fp-memberships"', $html);
        self::assertStringContainsString('aria-label=', $html);
        self::assertStringNotContainsString('<script>unsafe</script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_panel_has_native_light_dark_logos_and_keeps_web_guard(): void
    {
        $this->app['env'] = 'local';
        config(['fp.enabled' => true, 'fp.core_schema_only' => false]);
        $panel = (new \App\Providers\Filament\FpDevelopmentPanelProvider($this->app))->panel(Panel::make());
        self::assertTrue($panel->hasDarkMode());
        self::assertStringContainsString('/images/logolight.png', $panel->getBrandLogo());
        self::assertStringContainsString('/images/logodark.png', $panel->getDarkModeBrandLogo());
        self::assertSame('10rem', $panel->getBrandLogoHeight());
        self::assertSame('web', $panel->getAuthGuard());
    }

    public function test_public_onboarding_uses_shared_logo_asset(): void
    {
        $html = file_get_contents(resource_path('views/fp/onboarding.blade.php'));

        self::assertStringContainsString("asset('images/logo-black.png')", $html);
        self::assertStringNotContainsString("asset('fp/victory-logo.png')", $html);
    }

    public function test_theme_toggle_has_accessible_label_and_preserves_native_theme_event(): void
    {
        $html = view('components.fp.ui.theme-toggle')->render();
        self::assertStringContainsString('type="button"', $html);
        self::assertStringContainsString('aria-label="Gunakan mode gelap"', $html);
        self::assertStringContainsString('aria-pressed', $html);
        self::assertStringContainsString('theme-changed', $html);
        self::assertStringNotContainsString('wire:click', $html);
    }
}
