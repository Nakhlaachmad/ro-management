<?php

declare(strict_types=1);

namespace App\Services\Sso;

use Illuminate\Support\Facades\Cache;

final class JtiReplayGuard
{
    public function consume(string $jti): bool
    {
        $jti = trim($jti);

        return $jti !== '' && Cache::add(
            'sso:ro:jti:' . hash('sha256', $jti),
            true,
            now()->addSeconds($this->replayTtl()),
        );
    }

    private function replayTtl(): int
    {
        return max(30, min(300, (int) config('services.portal_sso.replay_ttl', 120)));
    }
}
