<?php

namespace Ramiz\LicenseClient;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\ServiceProvider;
use Ramiz\LicenseClient\Commands\LicenseRegisterCommand;
use Ramiz\LicenseClient\Commands\LicenseHeartbeatCommand;
use Ramiz\LicenseClient\Commands\LicenseStatusCommand;

class LicenseServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/sl_sync.php', 'sl_sync');

        $this->app->singleton(LicenseClient::class, fn() => new LicenseClient());
    }

    public function boot(): void
    {
        // Publish config (non-obvious name — Layer 3)
        $this->publishes([
            __DIR__ . '/../config/sl_sync.php' => config_path('sl_sync.php'),
        ], 'sl-sync');

        if ($this->app->runningInConsole()) {
            $this->commands([
                LicenseRegisterCommand::class,
                LicenseHeartbeatCommand::class,
                LicenseStatusCommand::class,
            ]);
        }

        // Schedule heartbeat every hour via the app's scheduler
        $this->callAfterResolving('schedule', function ($schedule) {
            $schedule->command('ramiz:heartbeat')->hourly()->withoutOverlapping();
        });

        // Register the middleware alias so the host app can use it
        $this->app['router']->aliasMiddleware('ramiz.license', \Ramiz\LicenseClient\Middleware\LicenseMiddleware::class);

        // Ping portal on every boot — only if license keys are configured
        // Skips ping on fresh clones / dev machines with no keys set
        if (!app()->runningInConsole() && !Cache::has('ramiz_pinged')) {
            try {
                $client = app(LicenseClient::class);
                if ($client->isConfigured()) {
                    $client->ping();
                    Cache::put('ramiz_pinged', true, now()->addMinutes(5));
                }
            } catch (\Throwable) {
                // Silent fail
            }
        }

        // Heartbeat on web request — fires once per hour via cache throttle
        // Works even if Laravel scheduler / cron is not configured
        if (!app()->runningInConsole() && !Cache::has('ramiz_heartbeat')) {
            try {
                $client = app(LicenseClient::class);
                if ($client->isConfigured()) {
                    $client->heartbeat();
                    Cache::put('ramiz_heartbeat', true, now()->addHour());
                }
            } catch (\Throwable) {
                // Silent fail
            }
        }
    }
}
