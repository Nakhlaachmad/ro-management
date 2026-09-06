<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\Sso\JwtSsoValidationException;
use App\Models\User;
use App\Services\Sso\JtiReplayGuard;
use App\Services\Sso\JwtSsoValidator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class JwtSsoController extends Controller
{
    private const ALLOWED_ROLES = ['admin', 'hrd', 'bm', 'ro'];

    public function __construct(
        private readonly JwtSsoValidator $validator,
        private readonly JtiReplayGuard $replayGuard,
    ) {
    }

    public function __invoke(Request $request): RedirectResponse
    {
        try {
            $claims = $this->validator->validate((string) $request->query('token', ''));
        } catch (JwtSsoValidationException $exception) {
            abort(403, $exception->getMessage());
        }

        if (! $this->replayGuard->consume($claims->jti)) {
            abort(403, 'Token SSO sudah pernah digunakan.');
        }

        $user = User::query()->where('sso_subject', trim($claims->sub))->first();
        if ($user === null) {
            abort(403, 'Akun RO belum tersinkron dengan IWACS.');
        }

        if (! $user->is_active || ! $user->hasAnyRole(self::ALLOWED_ROLES)) {
            abort(403, 'Akun RO tidak aktif atau tidak memiliki akses panel.');
        }

        $appRole = trim($claims->app_role);
        if (
            ! in_array($appRole, self::ALLOWED_ROLES, true)
            || ($claims->is_global_admin && ! $user->hasRole('admin'))
            || (! $claims->is_global_admin && ! $user->hasRole($appRole))
        ) {
            abort(403, 'Role akun RO tidak sesuai dengan token IWACS.');
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $request->session()->put('password_hash_web', $user->getAuthPassword());
        $request->session()->save();

        // Panel Filament RO memakai path dasar (/), bukan /admin.
        return redirect('/');
    }
}
