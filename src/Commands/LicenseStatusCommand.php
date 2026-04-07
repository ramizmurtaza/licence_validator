<?php

namespace Ramiz\LicenseClient\Commands;

use Illuminate\Console\Command;
use Ramiz\LicenseClient\LicenseClient;
use Ramiz\LicenseClient\Exceptions\TamperedException;

class LicenseStatusCommand extends Command
{
    protected $signature   = 'ramiz:status';
    protected $description = 'Check current license status';

    public function handle(LicenseClient $client): int
    {
        if (!$client->isConfigured()) {
            $this->error('Not configured. Run: php artisan ramiz:register');
            return self::FAILURE;
        }

        $this->line('Checking license...');

        // Force fresh check by clearing cache first
        $client->clearCache();

        try {
            $result = $client->check();
        } catch (TamperedException $e) {
            $this->error('SECURITY WARNING: ' . $e->getMessage());
            return self::FAILURE;
        }

        if (!empty($result['valid'])) {
            $this->info('License Status: VALID');
            $this->table(['Field', 'Value'], [
                ['Tenant',     $result['tenant']     ?? '—'],
                ['Product',    $result['product']    ?? '—'],
                ['Plan',       $result['plan']       ?? '—'],
                ['Expires',    $result['expires_at'] ?? '—'],
            ]);
        } else {
            $this->error('License Status: INVALID');
            $this->line('Reason: ' . ($result['message'] ?? 'Unknown'));
        }

        return self::SUCCESS;
    }
}
