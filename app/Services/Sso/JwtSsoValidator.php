<?php

declare(strict_types=1);

namespace App\Services\Sso;

use App\Exceptions\Sso\JwtSsoValidationException;
use DomainException;
use Firebase\JWT\BeforeValidException;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\SignatureInvalidException;
use InvalidArgumentException;
use stdClass;
use UnexpectedValueException;

/** Validasi JWT perpindahan IWACS -> RO tanpa meminta Keycloak di browser. */
final class JwtSsoValidator
{
    private const ALGORITHM = 'RS256';

    public function validate(string $token): stdClass
    {
        if (trim($token) === '') {
            throw new JwtSsoValidationException('empty_token', 'Token SSO tidak tersedia.');
        }

        $previousLeeway = JWT::$leeway;

        try {
            JWT::$leeway = $this->leeway();
            $claims = JWT::decode($token, new Key($this->publicKey(), self::ALGORITHM));
        } catch (SignatureInvalidException $exception) {
            throw new JwtSsoValidationException('invalid_signature', 'Signature token SSO tidak valid.', $exception);
        } catch (BeforeValidException $exception) {
            throw new JwtSsoValidationException('token_not_yet_valid', 'Token SSO belum dapat digunakan.', $exception);
        } catch (ExpiredException $exception) {
            throw new JwtSsoValidationException('expired_token', 'Token SSO sudah kedaluwarsa.', $exception);
        } catch (InvalidArgumentException|DomainException $exception) {
            throw new JwtSsoValidationException('jwt_configuration_error', 'Konfigurasi verifikasi JWT tidak valid.', $exception);
        } catch (UnexpectedValueException $exception) {
            throw new JwtSsoValidationException('invalid_token', 'Format atau isi token SSO tidak valid.', $exception);
        } finally {
            JWT::$leeway = $previousLeeway;
        }

        $this->validateClaims($claims);

        return $claims;
    }

    private function validateClaims(stdClass $claims): void
    {
        $issuer = trim((string) config('services.portal_sso.issuer', ''));
        if ($issuer === '' || ! isset($claims->iss) || ! is_string($claims->iss) || ! hash_equals($issuer, $claims->iss)) {
            throw new JwtSsoValidationException('wrong_issuer', 'Issuer token SSO tidak sesuai.');
        }

        $expectedAudience = trim((string) config('services.portal_sso.audience', 'ro'));
        $audience = $claims->aud ?? null;
        $audiences = is_string($audience) ? [$audience] : (is_array($audience) ? $audience : []);
        if (! in_array($expectedAudience, $audiences, true)) {
            throw new JwtSsoValidationException('wrong_audience', 'Token SSO bukan untuk RO Management.');
        }

        foreach (['sub', 'jti', 'app_role'] as $claim) {
            if (! isset($claims->{$claim}) || ! is_string($claims->{$claim}) || trim($claims->{$claim}) === '') {
                throw new JwtSsoValidationException('invalid_claim_' . $claim, "Claim {$claim} tidak tersedia atau tidak valid.");
            }
        }

        foreach (['iat', 'nbf', 'exp'] as $claim) {
            if (! isset($claims->{$claim}) || ! is_int($claims->{$claim})) {
                throw new JwtSsoValidationException('invalid_claim_' . $claim, "Claim {$claim} tidak tersedia atau tidak valid.");
            }
        }

        if (! property_exists($claims, 'is_global_admin') || ! is_bool($claims->is_global_admin)) {
            throw new JwtSsoValidationException('invalid_claim_is_global_admin', 'Claim is_global_admin tidak valid.');
        }

        if ($claims->exp <= $claims->iat || $claims->nbf < $claims->iat) {
            throw new JwtSsoValidationException('invalid_token_lifetime', 'Masa berlaku token SSO tidak valid.');
        }

        if (($claims->exp - $claims->iat) > $this->tokenTtl() + $this->leeway()) {
            throw new JwtSsoValidationException('token_lifetime_too_long', 'Masa berlaku token SSO terlalu panjang.');
        }
    }

    private function publicKey(): string
    {
        $configuredPath = trim((string) config('services.portal_sso.public_key_path', ''));
        $path = $this->isAbsolutePath($configuredPath) ? $configuredPath : base_path($configuredPath);

        if ($configuredPath === '' || ! is_file($path) || ! is_readable($path)) {
            throw new JwtSsoValidationException('public_key_not_found', 'Public key SSO tidak tersedia.');
        }

        $publicKey = file_get_contents($path);
        if (! is_string($publicKey) || ! str_contains($publicKey, '-----BEGIN PUBLIC KEY-----')) {
            throw new JwtSsoValidationException('public_key_invalid_format', 'Format public key SSO tidak valid.');
        }

        return $publicKey;
    }

    private function tokenTtl(): int
    {
        return max(30, min(300, (int) config('services.portal_sso.token_ttl', 120)));
    }

    private function leeway(): int
    {
        return max(0, min(5, (int) config('services.portal_sso.leeway', 5)));
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/') || str_starts_with($path, '\\\\') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
    }
}
