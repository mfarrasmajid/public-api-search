<?php

namespace App\Console\Commands;

use App\Models\Api;
use Illuminate\Console\Command;

/**
 * Machine readable row count, used by the container entrypoint to decide
 * whether the starter dataset still needs seeding.
 */
class ApiCountCommand extends Command
{
    protected $signature = 'apis:count';

    protected $description = 'Print the number of APIs stored in the database';

    public function handle(): int
    {
        $this->line((string) Api::count());

        return self::SUCCESS;
    }
}
