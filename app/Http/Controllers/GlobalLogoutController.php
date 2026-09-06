<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Api\IwacsRoClient;
use App\Services\Api\IwacsRoException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** Ends RO and the matching IWACS session through a one-time server handoff. */
final class GlobalLogoutController
{
    public function __invoke(Request $request, IwacsRoClient $iwacs): RedirectResponse
    {
        $user = $request->user();
        if (! $user instanceof User || blank($user->sso_subject)) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect('/login');
        }

        try {
            $result = $iwacs->issueLogoutTicket((string) $user->sso_subject);
            $logoutUrl = trim((string) ($result['logout_url'] ?? ''));
            if ($logoutUrl === '' || filter_var($logoutUrl, FILTER_VALIDATE_URL) === false) {
                throw new IwacsRoException('Respons logout IWACS tidak lengkap.');
            }
        } catch (IwacsRoException $exception) {
            return redirect('/')
                ->with('global_logout_error', $exception->getMessage());
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->away($logoutUrl);
    }
}
