<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\Api\ApiException;
use App\Services\Api\KeycloakAccessTokenValidator;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class AuthenticateApiToken
{
    public function __construct(private readonly KeycloakAccessTokenValidator $validator)
    {
    }

    public function handle(Request $request, Closure $next, string ...$requiredScopes): Response
    {
        try {
            if (! preg_match('/^Bearer\s+(.+)$/i', trim((string) $request->header('Authorization')), $matches)) {
                throw new ApiException('missing_bearer_token', 'Authorization Bearer token wajib dikirim.', 401);
            }
            $claims = $this->validator->validate(trim($matches[1]));
            foreach ($requiredScopes as $scope) {
                if (! in_array($scope, $claims['_scopes'] ?? [], true)) {
                    throw new ApiException('insufficient_scope', 'Scope access token tidak mencukupi.', 403, ['required_scope' => $scope]);
                }
            }
            $request->attributes->set('api_claims', $claims);

            return $next($request);
        } catch (ApiException $exception) {
            $response = response()->json([
                'error' => $exception->errorCode,
                'message' => $exception->getMessage(),
                'details' => $exception->details,
            ], $exception->status);
            if ($exception->status === 401) {
                $response->headers->set('WWW-Authenticate', 'Bearer');
            }

            return $response;
        }
    }
}
