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

        // Auto-register on first boot if not already registered
        $this->callAfterResolving(LicenseClient::class, function (LicenseClient $client) {
            if ($client->isConfigured() && !Cache::has('ramiz_registered')) {
                try {
                    $client->register(
                        config('app.name', 'Unknown'),
                    );
                    Cache::forever('ramiz_registered', true);
                } catch (\Throwable) {
                    // Silent fail — registration will retry on next boot
                }
            }
        });
    }
}
