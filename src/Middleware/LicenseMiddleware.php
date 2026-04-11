<?php

namespace Ramiz\LicenseClient\Middleware;

use Closure;
use Illuminate\Http\Request;
use Ramiz\LicenseClient\LicenseClient;
use Ramiz\LicenseClient\Exceptions\TamperedException;
use Symfony\Component\HttpFoundation\Response;

class LicenseMiddleware
{
    public function __construct(private LicenseClient $client) {}

    public function handle(Request $request, Closure $next): Response
    {
        // Skip license check for artisan/console
        if (app()->runningInConsole()) {
            return $next($request);
        }

        if (!$this->client->isConfigured()) {
            abort(503, 'Application not licensed. Run: php artisan ramiz:register');
        }

        try {
            $result = $this->client->check();
        } catch (TamperedException) {
            abort(403, 'License verification failed. Contact your administrator.');
        }

        if (empty($result['valid'])) {
            $message = $result['message'] ?? 'License is invalid or expired.';
            abort(403, $message);
        }

        // Make license info available to the app
        app()->instance('ramiz.license', $result);

        // Expiry warning — share with all views when expiring within 7 days
        if (!empty($result['expires_at'])) {
            $expiresAt   = \Carbon\Carbon::parse($result['expires_at']);
            $daysLeft    = now()->diffInDays($expiresAt, false); // false = signed (negative if past)
            if ($daysLeft >= 0 && $daysLeft <= 7) {
                view()->share('ramiz_expiry_warning', [
                    'days'       => (int) $daysLeft,
                    'expires_at' => $expiresAt->format('d M Y'),
                ]);
            }
        }

        return $next($request);
    }
}
