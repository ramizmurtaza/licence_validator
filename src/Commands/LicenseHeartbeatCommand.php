<?php

namespace Ramiz\LicenseClient\Commands;

use Illuminate\Console\Command;
use Ramiz\LicenseClient\LicenseClient;

class LicenseHeartbeatCommand extends Command
{
    protected $signature   = 'ramiz:heartbeat';
    protected $description = 'Send heartbeat ping to Ramiz licensing portal';

    public function handle(LicenseClient $client): int
    {
        if (!$client->isConfigured()) {
            $this->error('Not configured. Run: php artisan ramiz:register');
            return self::FAILURE;
        }

        $ok = $client->heartbeat();

        if ($ok) {
            $this->line('[' . now()->toDateTimeString() . '] Heartbeat sent.');
        } else {
            $this->warn('[' . now()->toDateTimeString() . '] Heartbeat failed — server may be unreachable.');
        }

        return self::SUCCESS;
    }
}
