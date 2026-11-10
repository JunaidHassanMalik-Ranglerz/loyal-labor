<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FcmPushService
{
    public function sendToToken(?string $token, string $title, string $body, array $data = []): bool
    {
        $token = trim((string) $token);
        if ($token === '') {
            return false;
        }

        $credentials = $this->credentials();
        if (!$credentials) {
            Log::warning('FCM skipped — firebase credentials not found');
            return false;
        }

        try {
            $accessToken = $this->accessToken($credentials);
            $projectId = $credentials['project_id'] ?? null;
            if (!$accessToken || !$projectId) {
                return false;
            }

            $stringData = [];
            foreach ($data as $key => $value) {
                $stringData[(string) $key] = is_scalar($value) ? (string) $value : json_encode($value);
            }

            $response = Http::withToken($accessToken)
                ->acceptJson()
                ->post('https://fcm.googleapis.com/v1/projects/' . $projectId . '/messages:send', [
                    'message' => [
                        'token' => $token,
                        'notification' => [
                            'title' => $title,
                            'body' => $body,
                        ],
                        'data' => $stringData,
                        'android' => [
                            'priority' => 'high',
                            'notification' => [
                                'sound' => 'default',
                                'click_action' => 'FLUTTER_NOTIFICATION_CLICK',
                            ],
                        ],
                        'apns' => [
                            'payload' => [
                                'aps' => [
                                    'sound' => 'default',
                                ],
                            ],
                        ],
                    ],
                ]);

            if (!$response->successful()) {
                Log::warning('FCM send failed', ['status' => $response->status(), 'body' => $response->body()]);
                return false;
            }

            return true;
        } catch (\Throwable $e) {
            Log::error('FCM send exception: ' . $e->getMessage());
            return false;
        }
    }

    private function credentials(): ?array
    {
        $path = (string) config('services.firebase.credentials');
        if ($path === '' || !is_file($path)) {
            $fallback = storage_path('app/firebase/loy-labor-firebase-adminsdk.json');
            $path = is_file($fallback) ? $fallback : '';
        }

        if ($path === '') {
            return null;
        }

        $json = json_decode((string) file_get_contents($path), true);

        return is_array($json) ? $json : null;
    }

    private function accessToken(array $credentials): ?string
    {
        return Cache::remember('fcm_access_token', 50 * 60, function () use ($credentials) {
            $now = time();
            $header = $this->b64url(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
            $claim = $this->b64url(json_encode([
                'iss' => $credentials['client_email'],
                'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                'aud' => 'https://oauth2.googleapis.com/token',
                'iat' => $now,
                'exp' => $now + 3600,
            ]));
            $unsigned = $header . '.' . $claim;
            $key = openssl_pkey_get_private($credentials['private_key'] ?? '');
            if (!$key) {
                return null;
            }
            openssl_sign($unsigned, $signature, $key, OPENSSL_ALGO_SHA256);
            $jwt = $unsigned . '.' . $this->b64url($signature);

            $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ]);

            $accessToken = $response->json('access_token');
            if (!$accessToken) {
                return null;
            }

            return $accessToken;
        });
    }

    private function b64url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
