<?php

declare(strict_types=1);

namespace App\Services\Api;

use App\Exceptions\Api\ApiException;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use JsonException;
use Throwable;

/** Validates only signed Keycloak service tokens; it never accepts a shared secret. */
final class KeycloakAccessTokenValidator
{
    /** @return array<string, mixed> */
    public function validate(string $token): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new ApiException('invalid_token', 'Bearer token bukan JWT yang valid.', 401);
        }

        $header = $this->decode($parts[0]);
        if (($header['alg'] ?? null) !== 'RS256' || ! is_string($header['kid'] ?? null)) {
            throw new ApiException('invalid_token_header', 'Header access token tidak valid.', 401);
        }

        $keys = $this->keys();
        if (! isset($keys[$header['kid']])) {
            $keys = $this->keys(true);
        }
        if (! isset($keys[$header['kid']])) {
            throw new ApiException('unknown_token_kid', 'Public key access token tidak ditemukan.', 401);
        }

        $previous = JWT::$leeway;
        JWT::$leeway = max(0, min(30, (int) config('api.leeway_seconds', 5)));
        try {
            $claims = (array) JWT::decode($token, $keys);
        } catch (Throwable $exception) {
            throw new ApiException('invalid_token', 'Access token ditolak.', 401, [], $exception);
        } finally {
            JWT::$leeway = $previous;
        }

        if (! hash_equals(rtrim((string) config('api.issuer'), '/'), rtrim((string) ($claims['iss'] ?? ''), '/'))) {
            throw new ApiException('wrong_token_issuer', 'Issuer access token tidak sesuai.', 401);
        }

        $audiences = is_string($claims['aud'] ?? null)
            ? [(string) $claims['aud']]
            : (is_array($claims['aud'] ?? null) ? array_values(array_filter($claims['aud'], 'is_string')) : []);
        if (! array_intersect($audiences, (array) config('api.audiences', []))) {
            throw new ApiException('wrong_token_audience', 'Audience access token tidak diizinkan.', 403);
        }

        $claims['_scopes'] = $this->scopes($claims);

        return $claims;
    }

    /** @return array<string, \Firebase\JWT\Key> */
    private function keys(bool $refresh = false): array
    {
        $discovery = $this->getJson((string) config('api.discovery_url'), 'discovery');
        $url = trim((string) ($discovery['jwks_uri'] ?? ''));
        if ($url === '') {
            throw new ApiException('missing_jwks_uri', 'Discovery tidak memiliki jwks_uri.', 503);
        }
        $cacheKey = 'api:oidc:jwks:'.hash('sha256', $url);
        if ($refresh) {
            Cache::forget($cacheKey);
        }
        $load = fn (): array => $this->getJson($url, 'jwks');
        $document = (int) config('api.jwks_cache_seconds', 3600) === 0
            ? $load()
            : Cache::remember($cacheKey, (int) config('api.jwks_cache_seconds', 3600), $load);
        if (! is_array($document['keys'] ?? null)) {
            throw new ApiException('invalid_jwks', 'JWKS tidak valid.', 503);
        }
        foreach ($document['keys'] as $key) {
            if (is_array($key) && array_intersect(['d', 'p', 'q', 'dp', 'dq', 'qi', 'oth'], array_keys($key)) !== []) {
                throw new ApiException('private_jwk_published', 'JWKS memuat material private key.', 503);
            }
        }
        try {
            return JWK::parseKeySet($document, 'RS256');
        } catch (Throwable $exception) {
            throw new ApiException('invalid_jwks', 'JWKS tidak dapat digunakan.', 503, [], $exception);
        }
    }

    /** @return array<string, mixed> */
    private function getJson(string $url, string $label): array
    {
        if ($url === '' || ! str_starts_with($url, 'http')) {
            throw new ApiException('invalid_oidc_url', "URL {$label} tidak valid.", 503);
        }
        try {
            $response = Http::acceptJson()
                ->connectTimeout(max(1, (int) config('api.connect_timeout', 5)))
                ->timeout(max(1, (int) config('api.timeout', 10)))
                ->retry(2, 200, null, false)
                ->get($url);
        } catch (Throwable $exception) {
            throw new ApiException('oidc_unavailable', "Keycloak {$label} tidak tersedia.", 503, [], $exception);
        }
        if ($response->failed()) {
            throw new ApiException('oidc_http_error', "Keycloak {$label} mengembalikan error.", 503);
        }
        try {
            $data = json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new ApiException('oidc_invalid_json', "Respons {$label} bukan JSON valid.", 503, [], $exception);
        }
        if (! is_array($data) || array_is_list($data)) {
            throw new ApiException('oidc_invalid_document', "Dokumen {$label} tidak valid.", 503);
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function decode(string $segment): array
    {
        $segment .= str_repeat('=', (4 - strlen($segment) % 4) % 4);
        $value = base64_decode(strtr($segment, '-_', '+/'), true);
        if ($value === false) {
            throw new ApiException('invalid_token_header', 'Header access token tidak valid.', 401);
        }
        try {
            $data = json_decode($value, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new ApiException('invalid_token_header', 'Header access token tidak valid.', 401, [], $exception);
        }
        if (! is_array($data) || array_is_list($data)) {
            throw new ApiException('invalid_token_header', 'Header access token tidak valid.', 401);
        }

        return $data;
    }

    /** @return list<string> */
    private function scopes(array $claims): array
    {
        $scope = $claims['scope'] ?? $claims['scp'] ?? '';
        $scopes = is_string($scope)
            ? array_values(array_filter(preg_split('/\s+/', trim($scope)) ?: []))
            : (is_array($scope) ? array_values(array_filter($scope, 'is_string')) : []);
        foreach ((array) ($claims['resource_access'] ?? []) as $resource) {
            if ($resource instanceof \stdClass) {
                $resource = (array) $resource;
            }
            foreach (is_array($resource) ? (array) ($resource['roles'] ?? []) : [] as $role) {
                if (is_string($role) && $role !== '') {
                    $scopes[] = $role;
                }
            }
        }

        return array_values(array_unique($scopes));
    }
}
