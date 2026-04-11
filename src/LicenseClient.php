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
            'base_uri'    => $this->portalEndpoint(),
            'timeout'     => 10,
            'verify'      => true,
            'http_errors' => false,
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
        if ($override = config('sl_sync.channel') ?? env('APP_SYNC_CHANNEL')) {
            return $override;
        }

        // Obfuscated product slugs resolved by build generation
        $slugs = [
            1 => base64_decode('bWVkcHJvLWhtcy12MQ=='), // medpro-hms-v1
            2 => base64_decode('bWVkcHJvLWhtcy12Mg=='), // medpro-hms-v2
        ];

        $build = (int) (config('sl_sync.build') ?? 2);

        return $slugs[$build] ?? $slugs[2];
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

        // Return cached valid result if still fresh (reduces API calls during normal use)
        if ($cached = Cache::get($cacheKey)) {
            return $cached;
        }

        $payload = ['installation_id' => $this->installationId()];
        $sig     = $this->signRequest($payload);

        try {
            $response = $this->http->post('check-installation', [
                'json'    => $payload,
                'headers' => [
                    'X-HMAC-Signature' => $sig,
                    'Accept'           => 'application/json',
                ],
            ]);

            $status = $response->getStatusCode();
            $body   = $response->getBody()->getContents();

            // Non-200 = disabled/expired/not found — block immediately, do NOT cache
            // so the next request re-checks right away (instant re-enable also works)
            if ($status !== 200) {
                $result = json_decode($body, true);
                return ['valid' => false, 'message' => $result['message'] ?? 'License is invalid or expired.'];
            }

            // Layer 2: verify the portal actually signed the response
            $responseSig = $response->getHeaderLine('X-Response-Signature');
            if (!$this->verifyResponse($body, $responseSig)) {
                throw new TamperedException('Response signature mismatch. Possible MITM attack.');
            }

            $result = json_decode($body, true);

            // Only cache valid responses — 1 minute
            if (!empty($result['valid'])) {
                Cache::put($cacheKey, $result, now()->addMinutes(1));
                // Also save grace cache for internet outages (24 hours)
                Cache::put($cacheKey . '_grace', $result, now()->addHours(24));
            }

            return $result;

        } catch (TamperedException $e) {
            throw $e;
        } catch (RequestException $e) {
            // Network failure — use grace cache so internet outages don't block the HIS
            return Cache::get($cacheKey . '_grace', ['valid' => false, 'message' => 'License server unreachable.']);
        }
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

    private function fingerprint(): string
    {
        $file    = storage_path('.ramiz_fingerprint');
        $sigFile = storage_path('.ramiz_fp_sig');
        $secret  = base64_decode('cmFtaXpfZnBfc2VjcmV0X2tleQ=='); // obfuscated signing key

        // Validate existing fingerprint
        if (file_exists($file) && file_exists($sigFile)) {
            $fp  = trim(file_get_contents($file));
            $sig = trim(file_get_contents($sigFile));
            if (hash_equals(hash_hmac('sha256', $fp, $secret), $sig)) {
                return $fp;
            }
        }

        // Hardware fallback — hard to fake all three together
        $hardware = implode('|', [
            gethostname(),
            gethostbyname(gethostname()),
            php_uname('m'),
        ]);
        $fp = hash('sha256', $hardware . base_path());

        // Save with signature
        try {
            file_put_contents($file, $fp);
            file_put_contents($sigFile, hash_hmac('sha256', $fp, $secret));
        } catch (\Throwable) {
            // Non-fatal — use hardware fingerprint without saving
        }

        return $fp;
    }

    public function ping(): bool
    {
        $fp       = $this->fingerprint();
        $cacheKey = 'ramiz_pinged_' . md5($fp);

        // Guard — do not ping again within 5 minutes for the same machine
        if (Cache::has($cacheKey)) {
            return true;
        }

        try {
            $response = $this->http->post('ping', [
                'json' => [
                    'app_name'    => config('app.name', 'Unknown'),
                    'app_url'     => config('app.url', ''),
                    'server_ip'   => request()->server('SERVER_ADDR', gethostbyname(gethostname())),
                    'php_version' => PHP_VERSION,
                    'hostname'    => gethostname(),
                    'local_ip'    => gethostbyname(gethostname()),
                    'node'        => $this->installationId() ?: null,
                    'channel'     => $this->productSlug() ?: null,
                    'fingerprint' => $fp,
                ],
                'headers' => ['Accept' => 'application/json'],
            ]);

            // Cache regardless of response so we don't flood the portal
            Cache::put($cacheKey, true, now()->addSeconds(10));

            return true;
        } catch (\Throwable) {
            // Network failure — don't cache, try again next request
            return false;
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
