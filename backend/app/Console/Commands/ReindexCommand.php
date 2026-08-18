<?php

namespace App\Console\Commands;

use App\Models\Api;
use App\Services\Indexing\ApiIndexer;
use Illuminate\Console\Command;

class ReindexCommand extends Command
{
    protected $signature = 'search:reindex';

    protected $description = 'Rebuild the OpenSearch index from PostgreSQL and flip the alias';

    public function handle(ApiIndexer $indexer): int
    {
        $total = Api::count();

        if ($total === 0) {
            $this->warn('No APIs in the database. Run: php artisan db:seed');

            return self::SUCCESS;
        }

        $this->info("Indexing {$total} APIs...");
        $bar = $this->output->createProgressBar($total);

        try {
            $result = $indexer->rebuild(function (int $done) use ($bar) {
                $bar->setProgress($done);
            });
        } catch (\Throwable $e) {
            $bar->finish();
            $this->newLine(2);
            $this->error('Reindex failed: '.$e->getMessage());
            $this->line('Is OpenSearch reachable? Try: curl http://localhost:9200');

            return self::FAILURE;
        }

        $bar->finish();
        $this->newLine(2);

        $this->info("Index: {$result['index']} (alias: ".config('opensearch.alias').')');
        $this->info("Indexed: {$result['indexed']}  Failed: {$result['failed']}");

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
