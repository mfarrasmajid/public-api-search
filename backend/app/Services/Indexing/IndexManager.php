<?php

namespace App\Services\Indexing;

use OpenSearch\Client;

/**
 * Owns the physical index lifecycle: mapping, creation and alias switching.
 *
 * Reindexing never writes into the alias directly. A new physical index is
 * built (apis_20240101120000), filled, then the alias is flipped in one
 * atomic call. Search keeps serving the old index until the flip.
 */
class IndexManager
{
    public function __construct(
        private readonly Client $client,
        private readonly string $alias,
    ) {
    }

    public function alias(): string
    {
        return $this->alias;
    }

    public function newPhysicalName(): string
    {
        return $this->alias.'_'.date('YmdHis');
    }

    public function exists(string $index): bool
    {
        return (bool) $this->client->indices()->exists(['index' => $index]);
    }

    public function create(string $index): void
    {
        $this->client->indices()->create([
            'index' => $index,
            'body' => [
                'settings' => $this->settings(),
                'mappings' => $this->mappings(),
            ],
        ]);
    }

    /**
     * Point the alias at $index and drop it from every other index.
     */
    public function switchAlias(string $index): void
    {
        $actions = [];

        try {
            $existing = $this->client->indices()->getAlias(['name' => $this->alias]);
            foreach (array_keys($existing) as $old) {
                if ($old !== $index) {
                    $actions[] = ['remove' => ['index' => $old, 'alias' => $this->alias]];
                }
            }
        } catch (\Throwable) {
            // Alias does not exist yet - nothing to remove.
        }

        $actions[] = ['add' => ['index' => $index, 'alias' => $this->alias]];

        $this->client->indices()->updateAliases(['body' => ['actions' => $actions]]);
    }

    /**
     * Delete old physical indices, keeping the $keep most recent ones.
     *
     * @return string[] deleted index names
     */
    public function pruneOldIndices(int $keep = 2): array
    {
        try {
            $indices = array_keys($this->client->indices()->get(['index' => $this->alias.'_*']));
        } catch (\Throwable) {
            return [];
        }

        rsort($indices);
        $stale = array_slice($indices, $keep);

        foreach ($stale as $index) {
            $this->client->indices()->delete(['index' => $index]);
        }

        return $stale;
    }

    public function delete(string $index): void
    {
        if ($this->exists($index)) {
            $this->client->indices()->delete(['index' => $index]);
        }
    }

    public function refresh(string $index): void
    {
        $this->client->indices()->refresh(['index' => $index]);
    }

    /**
     * Single shard / no replica: correct for a single-node POC. A replica on a
     * one-node cluster stays unassigned and turns cluster health yellow.
     */
    private function settings(): array
    {
        return [
            'number_of_shards' => 1,
            'number_of_replicas' => 0,
            'analysis' => [
                'filter' => [
                    'english_stemmer' => ['type' => 'stemmer', 'language' => 'english'],
                    'api_synonyms' => [
                        'type' => 'synonym',
                        'lenient' => true,
                        // Bridges the most common Indonesian <-> English gaps
                        // until semantic search (phase 5) takes over.
                        'synonyms' => [
                            'cuaca, weather, prakiraan, forecast',
                            'gempa, earthquake, seismic',
                            'kurs, exchange rate, currency, forex',
                            'saham, stock, equity',
                            'berita, news',
                            'gratis, free',
                            'peta, map, maps',
                            'alamat, address',
                            'sholat, shalat, prayer',
                            'libur, holiday',
                            'kesehatan, health',
                            'pembayaran, payment',
                            'terjemahan, translation, translate',
                        ],
                    ],
                ],
                'analyzer' => [
                    // Indexing: fold accents, stem english, keep it simple.
                    'api_text' => [
                        'type' => 'custom',
                        'tokenizer' => 'standard',
                        'filter' => ['lowercase', 'asciifolding', 'english_stemmer'],
                    ],
                    // Query time only: expanding synonyms at search time means
                    // editing the list does not require a full reindex.
                    'api_text_search' => [
                        'type' => 'custom',
                        'tokenizer' => 'standard',
                        'filter' => ['lowercase', 'asciifolding', 'api_synonyms', 'english_stemmer'],
                    ],
                    'api_folded' => [
                        'type' => 'custom',
                        'tokenizer' => 'standard',
                        'filter' => ['lowercase', 'asciifolding'],
                    ],
                ],
            ],
        ];
    }

    private function mappings(): array
    {
        $text = [
            'type' => 'text',
            'analyzer' => 'api_text',
            'search_analyzer' => 'api_text_search',
        ];

        return [
            'properties' => [
                'id' => ['type' => 'integer'],
                'name' => $text + [
                    'fields' => [
                        'keyword' => ['type' => 'keyword'],
                        'folded' => ['type' => 'text', 'analyzer' => 'api_folded'],
                    ],
                ],
                'slug' => ['type' => 'keyword'],
                'description' => $text + [
                    'fields' => [
                        'folded' => ['type' => 'text', 'analyzer' => 'api_folded'],
                    ],
                ],
                'category' => ['type' => 'text', 'analyzer' => 'api_text', 'search_analyzer' => 'api_text_search', 'fields' => ['keyword' => ['type' => 'keyword']]],
                'provider' => ['type' => 'text', 'analyzer' => 'api_text', 'fields' => ['keyword' => ['type' => 'keyword']]],
                'tags' => ['type' => 'text', 'analyzer' => 'api_text', 'search_analyzer' => 'api_text_search', 'fields' => ['keyword' => ['type' => 'keyword']]],
                'website' => ['type' => 'keyword', 'index' => false],
                'documentation_url' => ['type' => 'keyword', 'index' => false],
                'base_url' => ['type' => 'keyword', 'index' => false],
                'authentication_type' => ['type' => 'keyword'],
                'https' => ['type' => 'boolean'],
                'cors' => ['type' => 'keyword'],
                'country' => ['type' => 'keyword'],
                'status' => ['type' => 'keyword'],
                'license' => ['type' => 'keyword'],
                'has_openapi' => ['type' => 'boolean'],
                'quality_score' => ['type' => 'integer'],
                'endpoint_count' => ['type' => 'integer'],
                'endpoints_text' => ['type' => 'text', 'analyzer' => 'api_text', 'search_analyzer' => 'api_text_search'],
                'health_status' => ['type' => 'keyword'],
                'response_time_ms' => ['type' => 'integer'],
                'last_checked_at' => ['type' => 'date'],
                'updated_at' => ['type' => 'date'],
            ],
        ];
    }
}
