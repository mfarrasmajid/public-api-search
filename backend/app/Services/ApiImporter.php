<?php

namespace App\Services;

use App\Models\Api;
use App\Models\Category;
use App\Models\Provider;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Single upsert path for every ingestion route (seeder, crawler output,
 * manual JSON). Slug is the natural key, so re-running an import is safe.
 */
class ApiImporter
{
    public function __construct(private readonly QualityScorer $scorer)
    {
    }

    /**
     * @param  array<int,array<string,mixed>>  $records
     * @return array{created:int,updated:int,skipped:int}
     */
    public function import(array $records, string $source = 'manual'): array
    {
        $stats = ['created' => 0, 'updated' => 0, 'skipped' => 0];

        foreach ($records as $record) {
            if (empty($record['name'])) {
                $stats['skipped']++;

                continue;
            }

            try {
                $slug = $record['slug'] ?? Str::slug($record['name']);
                $existing = Api::where('slug', $slug)->first();

                $api = Api::updateOrCreate(['slug' => $slug], [
                    'name' => $record['name'],
                    'description' => $record['description'] ?? null,
                    'provider_id' => $this->providerId($record['provider'] ?? null, $record['country'] ?? null),
                    'category_id' => $this->categoryId($record['category'] ?? null),
                    'website' => $record['website'] ?? null,
                    'documentation_url' => $record['documentation_url'] ?? null,
                    'base_url' => $record['base_url'] ?? null,
                    'authentication_type' => $this->normaliseAuth($record['authentication_type'] ?? $record['auth'] ?? null),
                    'https' => (bool) ($record['https'] ?? true),
                    'cors' => $record['cors'] ?? 'unknown',
                    'status' => $record['status'] ?? 'active',
                    'version' => $record['version'] ?? null,
                    'license' => $record['license'] ?? null,
                    'country' => $record['country'] ?? null,
                    'source' => $record['source'] ?? $source,
                    'source_url' => $record['source_url'] ?? null,
                    'tags' => $record['tags'] ?? [],
                    'openapi_url' => $record['openapi_url'] ?? null,
                    'has_openapi' => (bool) ($record['has_openapi'] ?? ! empty($record['openapi_url'])),
                    'last_seen_at' => now(),
                ]);

                $api->forceFill(['quality_score' => $this->scorer->score($api)])->saveQuietly();

                $existing ? $stats['updated']++ : $stats['created']++;
            } catch (\Throwable $e) {
                $stats['skipped']++;
                Log::warning('Failed to import API record', [
                    'name' => $record['name'] ?? null,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $stats;
    }

    private function providerId(?string $name, ?string $country): ?int
    {
        if (blank($name)) {
            return null;
        }

        return Provider::firstOrCreate(
            ['slug' => Str::slug($name)],
            ['name' => $name, 'country' => $country]
        )->id;
    }

    private function categoryId(?string $name): ?int
    {
        if (blank($name)) {
            return null;
        }

        return Category::firstOrCreate(
            ['slug' => Str::slug($name)],
            ['name' => $name]
        )->id;
    }

    /**
     * Directories spell authentication a dozen ways ("apiKey", "API Key",
     * "X-Mashape-Key", ""). Collapse them into the four values the filter UI
     * knows about.
     */
    private function normaliseAuth(?string $value): string
    {
        $value = trim(mb_strtolower((string) $value));

        return match (true) {
            $value === '' || $value === 'none' || $value === 'no' => 'none',
            str_contains($value, 'oauth') => 'OAuth',
            str_contains($value, 'bearer') || str_contains($value, 'jwt') => 'bearer',
            str_contains($value, 'key') => 'apiKey',
            default => 'unknown',
        };
    }
}
