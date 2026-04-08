<?php

namespace Ramiz\LicenseClient;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Cache;
use Ramiz\LicenseClient\Exceptions\LicenseException;
use Ramiz\LicenseClient\Exceptions\TamperedException;

class LicenseClient
{
    private Client $http;

    public function __construct()
    {
        $this->http = new Client([
            'base_uri' => $this->portalEndpoint(),
            'timeout'  => 10,
            'verify'   => true,
        ]);
    }

    // ── Layer 1: URL hardcoded + obfuscated ─────────────────────────────
    private function portalEndpoint(): string
    {
        // Split, base64-encoded, assembled at runtime
        // Changing this in vendor files breaks on every composer update
        $s = ['aHR0cHM6Ly', '9wb3J0YWwu', 'bWVkcHJvZC', '54eXovYXBp', 'L3YxLw=='];
        return base64_decode(implode('', $s));
    }

    // ── Layer 3: non-obvious env key names ──────────────────────────────
    private function installationId(): string
    {
        return config('sl_sync.node') ?? env('APP_SYNC_NODE', '');
    }

    private function secretKey(): string
    {
        return config('sl_sync.token') ?? env('APP_SYNC_TOKEN', '');
    }

    private function productSlug(): string
    {
        return config('sl_sync.channel') ?? env('APP_SYNC_CHANNEL', '');
    }

    // ── Layer 2: HMAC sign outgoing request ─────────────────────────────
    private function signRequest(array $payload): string
    {
        return hash_hmac('sha256', json_encode($payload), $this->secretKey());
    }

    // ── Layer 2: Verify portal signed the response ──────────────────────
    private function verifyResponse(string $responseBody, string $signature): bool
    {
        $expected = hash_hmac('sha256', $responseBody, $this->secretKey());
        return hash_equals($expected, $signature);
    }

    public function register(string $hospitalName, ?string $email = null, ?string $phone = null, ?string $contactName = null): array
    {
        $payload = array_filter([
            'product_slug'  => $this->productSlug(),
            'hospital_name' => $hospitalName,
            'contact_email' => $email,
            'contact_phone' => $phone,
            'contact_name'  => $contactName,
        ]);

        try {
            $response = $this->http->post('register-installation', [
                'json'    => $payload,
                'headers' => [
                    'X-HMAC-Signature' => $this->signRequest($payload),
                    'Accept'           => 'application/json',
                ],
            ]);

            return json_decode($response->getBody()->getContents(), true);
        } catch (RequestException $e) {
            throw new LicenseException('Registration failed: ' . $e->getMessage());
        }
    }

    public function check(): array
    {
        $cacheKey = 'ramiz_lv_' . md5($this->installationId());

        return Cache::remember($cacheKey, now()->addHours(24), function () {
            $payload = ['installation_id' => $this->installationId()];
            $sig     = $this->signRequest($payload);

            try {
                $response     = $this->http->post('check-installation', [
                    'json'    => $payload,
                    'headers' => [
                        'X-HMAC-Signature' => $sig,
                        'Accept'           => 'application/json',
                    ],
                ]);

                $body          = $response->getBody()->getContents();
                $responseSig   = $response->getHeaderLine('X-Response-Signature');

                // Layer 2: verify the portal actually signed the response
                if (!$this->verifyResponse($body, $responseSig)) {
                    throw new TamperedException('Response signature mismatch. Possible MITM attack.');
                }

                return json_decode($body, true);

            } catch (TamperedException $e) {
                throw $e;
            } catch (RequestException $e) {
                // Network failure — allow grace period using cached result
                return Cache::get($cacheKey . '_grace', ['valid' => false, 'message' => 'License server unreachable.']);
            }
        });
    }

    public function heartbeat(): bool
    {
        $payload = [
            'installation_id' => $this->installationId(),
            'timestamp'       => now()->toISOString(),
        ];

        try {
            $this->http->post('heartbeat', [
                'json'    => $payload,
                'headers' => [
                    'X-HMAC-Signature' => $this->signRequest($payload),
                    'Accept'           => 'application/json',
                ],
            ]);
            return true;
        } catch (RequestException) {
            return false; // heartbeat failure is non-fatal
        }
    }

    public function ping(): void
    {
        try {
            $this->http->post('ping', [
                'json' => [
                    'app_name'   => config('app.name', 'Unknown'),
                    'app_url'    => config('app.url', ''),
                    'server_ip'  => request()->server('SERVER_ADDR', gethostbyname(gethostname())),
                    'php_version'=> PHP_VERSION,
                    'node'       => $this->installationId() ?: null,
                    'channel'    => $this->productSlug() ?: null,
                ],
                'headers' => ['Accept' => 'application/json'],
            ]);
        } catch (\Throwable) {
            // Silent — ping is best-effort
        }
    }

    public function clearCache(): void
    {
        Cache::forget('ramiz_lv_' . md5($this->installationId()));
    }

    public function isConfigured(): bool
    {
        return !empty($this->installationId()) && !empty($this->secretKey()) && !empty($this->productSlug());
    }
}
