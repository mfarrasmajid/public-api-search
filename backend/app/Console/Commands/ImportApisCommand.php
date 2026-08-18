<?php

namespace App\Console\Commands;

use App\Services\ApiImporter;
use Illuminate\Console\Command;

class ImportApisCommand extends Command
{
    protected $signature = 'apis:import {file : Path to a JSON file with an array of API objects}
                            {--source=manual : Value stored in apis.source}
                            {--reindex : Reindex after import}';

    protected $description = 'Import/upsert APIs from a JSON file (the crawler output format)';

    public function handle(ApiImporter $importer): int
    {
        $path = $this->argument('file');

        if (! is_file($path)) {
            $this->error("File not found: {$path}");

            return self::FAILURE;
        }

        $payload = json_decode((string) file_get_contents($path), true);

        if (! is_array($payload)) {
            $this->error('File does not contain a valid JSON array.');

            return self::FAILURE;
        }

        $stats = $importer->import($payload, $this->option('source'));

        $this->info("Created: {$stats['created']}  Updated: {$stats['updated']}  Skipped: {$stats['skipped']}");

        if ($this->option('reindex')) {
            $this->call('search:reindex');
        }

        return self::SUCCESS;
    }
}
