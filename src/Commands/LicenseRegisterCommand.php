<?php

namespace Ramiz\LicenseClient\Commands;

use Illuminate\Console\Command;
use Ramiz\LicenseClient\LicenseClient;
use Ramiz\LicenseClient\Exceptions\LicenseException;

class LicenseRegisterCommand extends Command
{
    protected $signature   = 'ramiz:register';
    protected $description = 'Register this installation with Ramiz licensing portal';

    public function handle(LicenseClient $client): int
    {
        $this->info('Ramiz License Registration');
        $this->line('----------------------------');

        if ($client->isConfigured()) {
            $this->warn('This installation is already registered.');
            if (!$this->confirm('Re-register anyway?')) {
                return self::SUCCESS;
            }
        }

        $hospitalName = $this->ask('Hospital / Organization name');
        $email        = $this->ask('Contact email (optional)', null);
        $phone        = $this->ask('Contact phone (optional)', null);
        $contactName  = $this->ask('Contact person name (optional)', null);

        $this->line('');
        $this->line('Contacting licensing portal...');

        try {
            $result = $client->register($hospitalName, $email, $phone, $contactName);
        } catch (LicenseException $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        if (empty($result['registered'])) {
            $this->error($result['message'] ?? 'Registration failed.');
            return self::FAILURE;
        }

        $this->info('');
        $this->info('Registration successful!');
        $this->line($result['message']);
        $this->line('');
        $this->warn('Next steps:');
        $this->line('1. The portal administrator will review your registration.');
        $this->line('2. Once confirmed, they will send you your credentials.');
        $this->line('3. Add the credentials to your .env file:');
        $this->line('');
        $this->line('   APP_SYNC_NODE=<installation_id>');
        $this->line('   APP_SYNC_TOKEN=<secret_key>');
        $this->line('   APP_SYNC_CHANNEL=<product_slug>');
        $this->line('');

        return self::SUCCESS;
    }
}
