<?php

namespace App\Console\Commands;

use App\Models\Api;
use Illuminate\Console\Command;
use OpenSearch\Client;

class IndexStatusCommand extends Command
{
    protected $signature = 'search:status';

    protected $description = 'Show cluster health, alias target and document count';

    public function handle(Client $client): int
    {
        $alias = config('opensearch.alias');

        try {
            $health = $client->cluster()->health();
            $count = $client->count(['index' => $alias])['count'] ?? 0;
            $aliases = array_keys($client->indices()->getAlias(['name' => $alias]));
        } catch (\Throwable $e) {
            $this->error('OpenSearch unreachable or index missing: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->table(['Metric', 'Value'], [
            ['Cluster status', $health['status'] ?? 'unknown'],
            ['Nodes', $health['number_of_nodes'] ?? '-'],
            ['Alias', $alias],
            ['Physical index', implode(', ', $aliases)],
            ['Documents indexed', $count],
            ['Rows in PostgreSQL', Api::count()],
        ]);

        if ($count !== Api::count()) {
            $this->warn('Index and database are out of sync. Run: php artisan search:reindex');
        }

        return self::SUCCESS;
    }
}
