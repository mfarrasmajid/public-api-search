<?php

namespace App\Services\Indexing;

use App\Models\Api;
use Illuminate\Support\Facades\Log;
use OpenSearch\Client;

class ApiIndexer
{
    public function __construct(
        private readonly Client $client,
        private readonly IndexManager $indexManager,
        private readonly int $bulkSize = 250,
    ) {
    }

    /**
     * Full rebuild: new index -> bulk load -> alias flip -> prune old ones.
     *
     * @param  callable|null  $progress  fn(int $indexedSoFar): void
     * @return array{index:string,indexed:int,failed:int}
     */
    public function rebuild(?callable $progress = null): array
    {
        $index = $this->indexManager->newPhysicalName();
        $this->indexManager->create($index);

        $indexed = 0;
        $failed = 0;

        Api::query()
            ->with(['provider', 'category', 'endpoints', 'latestHealthCheck'])
            ->chunkById($this->bulkSize, function ($apis) use ($index, &$indexed, &$failed, $progress) {
                $result = $this->bulkIndex($apis->all(), $index);
                $indexed += $result['indexed'];
                $failed += $result['failed'];

                if ($progress) {
                    $progress($indexed);
                }
            });

        $this->indexManager->refresh($index);
        $this->indexManager->switchAlias($index);
        $this->indexManager->pruneOldIndices();

        Api::query()->whereNull('indexed_at')->orWhere('indexed_at', '<', now())->update(['indexed_at' => now()]);

        return ['index' => $index, 'indexed' => $indexed, 'failed' => $failed];
    }

    /**
     * Index (or re-index) a single API into the live alias. Used after an
     * admin edit or a crawler upsert, so single records stay fresh without
     * paying for a full rebuild.
     */
    public function indexOne(Api $api): void
    {
        $this->client->index([
            'index' => $this->indexManager->alias(),
            'id' => (string) $api->id,
            'body' => $api->toSearchDocument(),
            'refresh' => true,
        ]);

        $api->forceFill(['indexed_at' => now()])->saveQuietly();
    }

    public function deleteOne(int $apiId): void
    {
        try {
            $this->client->delete([
                'index' => $this->indexManager->alias(),
                'id' => (string) $apiId,
                'refresh' => true,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Failed to delete document from index', ['api_id' => $apiId, 'error' => $e->getMessage()]);
        }
    }

    /**
     * @param  Api[]  $apis
     * @return array{indexed:int,failed:int}
     */
    private function bulkIndex(array $apis, string $index): array
    {
        if ($apis === []) {
            return ['indexed' => 0, 'failed' => 0];
        }

        $body = [];
        foreach ($apis as $api) {
            $body[] = ['index' => ['_index' => $index, '_id' => (string) $api->id]];
            $body[] = $api->toSearchDocument();
        }

        $response = $this->client->bulk(['body' => $body]);

        $failed = 0;
        foreach ($response['items'] ?? [] as $item) {
            if (isset($item['index']['error'])) {
                $failed++;
                Log::warning('Bulk index item failed', ['error' => $item['index']['error']]);
            }
        }

        return ['indexed' => count($apis) - $failed, 'failed' => $failed];
    }
}
