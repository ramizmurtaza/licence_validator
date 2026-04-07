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

        return $next($request);
    }
}
