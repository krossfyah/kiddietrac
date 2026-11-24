<?php

namespace App\Console\Commands;

use App\Support\ServerHealth;
use Illuminate\Console\Command;

/** Every 5 minutes: one row of server health for the super admin's history charts. */
class ServerHealthSample extends Command
{
    protected $signature = 'kt:server-health-sample';

    protected $description = 'Record CPU, memory, disk, database and queue figures for the Server health page';

    public function handle(): int
    {
        try {
            ServerHealth::sample();
        } catch (\Throwable $e) {
            $this->warn('Server health sample failed: ' . $e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
