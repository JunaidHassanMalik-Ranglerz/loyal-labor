<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use UnexpectedValueException;

class SocialAuthService
{
    /**
     * Verify Google ID token from mobile SDK.
     *
     * @return array{provider:string,provider_id:string,email:?string,name:?string,avatar:?string,email_verified:bool}
     */
    public function verifyGoogleIdToken(string $idToken): array
    {
        $response = Http::timeout(12)->get('https://oauth2.googleapis.com/tokeninfo', [
            'id_token' => $idToken,
        ]);

        if (!$response->successful()) {
            throw new UnexpectedValueException('Invalid Google token.');
        }

        $payload = $response->json();
        if (!is_array($payload) || empty($payload['sub'])) {
            throw new UnexpectedValueException('Invalid Google token payload.');
        }

        $audience = (string) ($payload['aud'] ?? '');
        $allowed = $this->googleClientIds();
        if ($allowed !== [] && $audience !== '' && !in_array($audience, $allowed, true)) {
            throw new UnexpectedValueException('Google token audience mismatch. Check GOOGLE_CLIENT_ID.');
        }

        $emailVerified = ($payload['email_verified'] ?? false) === true
            || ($payload['email_verified'] ?? '') === 'true'
            || ($payload['email_verified'] ?? '') === '1';

        return [
            'provider' => 'google',
            'provider_id' => (string) $payload['sub'],
            'email' => !empty($payload['email']) ? strtolower(trim((string) $payload['email'])) : null,
            'name' => $payload['name'] ?? ($payload['given_name'] ?? null),
            'avatar' => $payload['picture'] ?? null,
            'email_verified' => $emailVerified || !empty($payload['email']),
        ];
    }

    /**
     * Verify Apple identity token (JWT) from Sign in with Apple.
     *
     * @return array{provider:string,provider_id:string,email:?string,name:?string,avatar:?string,email_verified:bool}
     */
    public function verifyAppleIdentityToken(string $identityToken, ?string $fallbackEmail = null, ?string $fallbackName = null): array
    {
        $parts = explode('.', $identityToken);
        if (count($parts) !== 3) {
            throw new UnexpectedValueException('Invalid Apple identity token.');
        }

        [$headerB64, $payloadB64, $signatureB64] = $parts;
        $header = json_decode($this->base64UrlDecode($headerB64), true);
        $payload = json_decode($this->base64UrlDecode($payloadB64), true);

        if (!is_array($header) || !is_array($payload) || empty($header['kid']) || empty($payload['sub'])) {
            throw new UnexpectedValueException('Invalid Apple token payload.');
        }

        if (($payload['iss'] ?? null) !== 'https://appleid.apple.com') {
            throw new UnexpectedValueException('Invalid Apple token issuer.');
        }

        if (($payload['exp'] ?? 0) < time()) {
            throw new UnexpectedValueException('Apple token has expired.');
        }

        $audience = $payload['aud'] ?? null;
        $allowedAudiences = $this->appleClientIds();
        if ($allowedAudiences !== [] && $audience && !in_array((string) $audience, $allowedAudiences, true)) {
            throw new UnexpectedValueException('Apple token audience mismatch. Check APPLE_CLIENT_ID.');
        }

        $publicKey = $this->applePublicKeyPem((string) $header['kid']);
        $signature = $this->base64UrlDecode($signatureB64);
        $signedData = $headerB64 . '.' . $payloadB64;

        $ok = openssl_verify($signedData, $signature, $publicKey, OPENSSL_ALGO_SHA256);
        if ($ok !== 1) {
            throw new UnexpectedValueException('Apple token signature verification failed.');
        }

        $email = !empty($payload['email'])
            ? strtolower(trim((string) $payload['email']))
            : ($fallbackEmail ? strtolower(trim($fallbackEmail)) : null);

        return [
            'provider' => 'apple',
            'provider_id' => (string) $payload['sub'],
            'email' => $email,
            'name' => $fallbackName ?: null,
            'avatar' => null,
            'email_verified' => !empty($payload['email_verified']) || !empty($email),
        ];
    }

    protected function googleClientIds(): array
    {
        $raw = env('GOOGLE_CLIENT_IDS', env('GOOGLE_CLIENT_ID', ''));
        $ids = array_values(array_filter(array_map('trim', explode(',', (string) $raw))));

        $setting = Cache::get('setting');
        if ($setting && !empty($setting->google_client_id)) {
            $ids[] = trim((string) $setting->google_client_id);
        }

        $configId = config('services.google.client_id');
        if ($configId) {
            $ids[] = trim((string) $configId);
        }

        return array_values(array_unique(array_filter($ids)));
    }

    protected function appleClientIds(): array
    {
        $raw = env('APPLE_CLIENT_IDS', env('APPLE_CLIENT_ID', ''));
        $ids = array_values(array_filter(array_map('trim', explode(',', (string) $raw))));

        $configId = config('services.apple.client_id');
        if ($configId) {
            $ids[] = trim((string) $configId);
        }

        return array_values(array_unique(array_filter($ids)));
    }

    protected function applePublicKeyPem(string $kid): string
    {
        $keys = Cache::remember('apple_auth_keys', 3600, function () {
            $response = Http::timeout(12)->get('https://appleid.apple.com/auth/keys');
            if (!$response->successful()) {
                throw new UnexpectedValueException('Could not fetch Apple public keys.');
            }

            return $response->json('keys') ?? [];
        });

        $jwk = null;
        foreach ($keys as $key) {
            if (($key['kid'] ?? null) === $kid) {
                $jwk = $key;
                break;
            }
        }

        if (!$jwk) {
            Cache::forget('apple_auth_keys');
            throw new UnexpectedValueException('Apple public key not found for this token.');
        }

        return $this->jwkToPem($jwk);
    }

    protected function jwkToPem(array $jwk): string
    {
        $modulus = $this->base64UrlDecode($jwk['n'] ?? '');
        $exponent = $this->base64UrlDecode($jwk['e'] ?? '');

        if ($modulus === '' || $exponent === '') {
            throw new UnexpectedValueException('Invalid Apple JWK.');
        }

        $rsaPublicKey = $this->asn1Sequence(
            $this->asn1Integer($modulus) . $this->asn1Integer($exponent)
        );

        $algorithmIdentifier = hex2bin('300d06092a864886f70d0101010500');
        $subjectPublicKey = chr(0x03) . $this->asn1Length(strlen($rsaPublicKey) + 1) . "\x00" . $rsaPublicKey;
        $spki = $this->asn1Sequence($algorithmIdentifier . $subjectPublicKey);

        return "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(base64_encode($spki), 64, "\n")
            . "-----END PUBLIC KEY-----\n";
    }

    protected function asn1Sequence(string $value): string
    {
        return "\x30" . $this->asn1Length(strlen($value)) . $value;
    }

    protected function asn1Integer(string $value): string
    {
        if ($value !== '' && (ord($value[0]) & 0x80)) {
            $value = "\x00" . $value;
        }

        return "\x02" . $this->asn1Length(strlen($value)) . $value;
    }

    protected function asn1Length(int $length): string
    {
        if ($length < 0x80) {
            return chr($length);
        }

        $temp = ltrim(pack('N', $length), "\x00");

        return chr(0x80 | strlen($temp)) . $temp;
    }

    protected function base64UrlDecode(string $data): string
    {
        $remainder = strlen($data) % 4;
        if ($remainder) {
            $data .= str_repeat('=', 4 - $remainder);
        }

        return (string) base64_decode(strtr($data, '-_', '+/'));
    }
}
