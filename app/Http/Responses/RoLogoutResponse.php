<?php

declare(strict_types=1);

namespace App\Http\Responses;

use Filament\Http\Responses\Auth\Contracts\LogoutResponse as LogoutResponseContract;
use Illuminate\Http\RedirectResponse;
use Livewire\Features\SupportRedirects\Redirector;

/** Respons logout bawaan Filament: keluar dari RO lalu kembali ke login IWACS. */
final class RoLogoutResponse implements LogoutResponseContract
{
    public function toResponse($request): RedirectResponse|Redirector
    {
        $portalUrl = rtrim(trim((string) config('services.portal_sso.issuer', '')), '/');

        if ($portalUrl === '' || filter_var($portalUrl, FILTER_VALIDATE_URL) === false) {
            return redirect('/login');
        }

        return redirect()->away($portalUrl . '/login');
    }
}
