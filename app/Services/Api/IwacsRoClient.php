<?php

declare(strict_types=1);

namespace App\Services\Api;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/** One-user client for RO-originated identity changes. */
final class IwacsRoClient
{
    /** @param array<string, mixed> $profile
     *  @return array<string, mixed>
     */
    public function createUser(array $profile, string $idempotencyKey): array
    {
        return $this->request('POST', '', $profile, $idempotencyKey, 'IWACS menolak pembuatan akun RO.');
    }

    /** @param array<string, mixed> $changes
     *  @return array<string, mixed>
     */
    public function updateUser(string $subject, int $expectedVersion, array $changes, string $sourceEmail): array
    {
        return $this->request('PUT', '/'.rawurlencode($subject), [
            'expected_version' => $expectedVersion,
            'source_email' => strtolower(trim($sourceEmail)),
            'changes' => $changes,
        ], 'ro-update-'.Str::uuid(), 'IWACS menolak perubahan akun RO.', true);
    }

    /** @return array<string, mixed> */
    public function updatePassword(string $subject, int $expectedVersion, string $password, string $sourceEmail): array
    {
        return $this->request('PUT', '/'.rawurlencode($subject).'/password', [
            'expected_version' => $expectedVersion,
            'source_email' => strtolower(trim($sourceEmail)),
            'password' => $password,
        ], 'ro-password-'.Str::uuid(), 'IWACS menolak perubahan password RO.');
    }

    /** @return array<string, mixed> */
    public function lookupByEmail(string $email): array
    {
        $settings = $this->settings();
        $url = trim((string) ($settings['lookup_url'] ?? ''));
        if ($url === '') {
            throw new IwacsRoException('Konfigurasi pemetaan akun RO ke IWACS belum lengkap.');
        }

        // The email is query data and must not be added to an access token or log.
        $response = $this->authenticatedRequest('GET', $url.'?'.http_build_query(['email' => strtolower(trim($email))]), [], 'ro-lookup-'.Str::uuid());
        if ($response->failed()) {
            $message = trim((string) $response->json('message'));
            throw new IwacsRoException($message !== '' ? $message : 'Akun RO belum terhubung ke IWACS.');
        }
        $data = $response->json('data');
        if (! is_array($data) || trim((string) ($data['sso_subject'] ?? '')) === '') {
            throw new IwacsRoException('Respons pemetaan IWACS tidak lengkap.');
        }

        return $data;
    }

    /** @return array<string, mixed> */
    public function issueLogoutTicket(string $subject): array
    {
        return $this->request('POST', '/logout-ticket', [
            'sso_subject' => trim($subject),
        ], 'ro-logout-'.Str::uuid(), 'IWACS tidak dapat menyiapkan logout.', false);
    }

    /** @param array<string, mixed> $payload
     *  @return array<string, mixed>
     */
    private function request(string $method, string $suffix, array $payload, string $idempotencyKey, string $fallback, bool $retryVersionConflict = false): array
    {
        $settings = $this->settings();
        $baseUrl = rtrim((string) ($settings['url'] ?? ''), '/');
        if ($baseUrl === '') {
            throw new IwacsRoException('Konfigurasi sinkronisasi RO ke IWACS belum lengkap. Perubahan RO tidak disimpan.');
        }
        $response = $this->authenticatedRequest($method, $baseUrl.$suffix, $payload, $idempotencyKey);
        if ($response->failed() && $retryVersionConflict && $response->status() === 409 && $response->json('error') === 'profile_version_conflict') {
            $current = $response->json('details.current_version') ?? $response->json('current_version');
            if (is_numeric($current) && (int) $current >= 1) {
                $payload['expected_version'] = (int) $current;

                return $this->request($method, $suffix, $payload, 'ro-retry-'.Str::uuid(), $fallback, false);
            }
        }
        if ($response->failed()) {
            $message = trim((string) $response->json('message'));
            throw new IwacsRoException($message !== '' ? $message : $fallback);
        }
        $data = $response->json();
        if (! is_array($data) || trim((string) ($data['sso_subject'] ?? '')) === '') {
            throw new IwacsRoException('Respons IWACS tidak menyertakan identitas akun.');
        }

        return $data;
    }

    /** @param array<string, mixed> $payload */
    private function authenticatedRequest(string $method, string $url, array $payload, string $idempotencyKey): \Illuminate\Http\Client\Response
    {
        $settings = $this->settings();
        try {
            $discovery = Http::acceptJson()
                ->connectTimeout(max(1, (int) ($settings['connect_timeout'] ?? 5)))
                ->timeout(max(1, (int) ($settings['timeout'] ?? 10)))
                ->get((string) $settings['discovery_url']);
            $endpoint = (string) $discovery->json('token_endpoint');
            if ($discovery->failed() || $endpoint === '') {
                throw new IwacsRoException('Keycloak tidak menyediakan token layanan RO.');
            }
            $tokenResponse = Http::acceptJson()
                ->asForm()
                ->withBasicAuth((string) $settings['client_id'], (string) $settings['client_secret'])
                ->connectTimeout(max(1, (int) ($settings['connect_timeout'] ?? 5)))
                ->timeout(max(1, (int) ($settings['timeout'] ?? 10)))
                ->post($endpoint, ['grant_type' => 'client_credentials', 'scope' => 'openid']);
            $token = $tokenResponse->json('access_token');
            if ($tokenResponse->failed() || ! is_string($token) || $token === '') {
                throw new IwacsRoException('Keycloak menolak credential layanan RO.');
            }

            return Http::acceptJson()
                ->asJson()
                ->withToken($token)
                ->withHeaders(['Idempotency-Key' => $idempotencyKey])
                ->connectTimeout(max(1, (int) ($settings['connect_timeout'] ?? 5)))
                ->timeout(max(1, (int) ($settings['timeout'] ?? 10)))
                ->send($method, $url, $method === 'GET' ? [] : ['json' => $payload]);
        } catch (IwacsRoException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new IwacsRoException('RO tidak dapat terhubung ke layanan sinkronisasi IWACS.', 0, $exception);
        }
    }

    /** @return array<string, mixed> */
    private function settings(): array
    {
        $settings = config('services.iwacs_ro');
        if (! is_array($settings) || ! ($settings['enabled'] ?? false)) {
            throw new IwacsRoException('Sinkronisasi RO ke IWACS belum diaktifkan. Perubahan RO tidak disimpan.');
        }
        foreach (['client_id', 'client_secret', 'discovery_url'] as $key) {
            if (trim((string) ($settings[$key] ?? '')) === '') {
                throw new IwacsRoException('Konfigurasi sinkronisasi RO ke IWACS belum lengkap. Perubahan RO tidak disimpan.');
            }
        }

        return $settings;
    }
}
