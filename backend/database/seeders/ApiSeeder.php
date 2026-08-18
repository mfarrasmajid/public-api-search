<?php

namespace Database\Seeders;

use App\Services\ApiImporter;
use Illuminate\Database\Seeder;

/**
 * Loads the curated starter dataset (>100 real public APIs) used by the
 * phase-1 POC. Idempotent: re-running updates instead of duplicating.
 */
class ApiSeeder extends Seeder
{
    public function run(): void
    {
        $path = database_path('seeders/data/apis.seed.json');

        if (! is_file($path)) {
            $this->command?->error("Seed file missing: {$path}");

            return;
        }

        $records = json_decode((string) file_get_contents($path), true) ?? [];

        $stats = app(ApiImporter::class)->import($records, 'seed');

        $this->command?->info(sprintf(
            'APIs seeded - created: %d, updated: %d, skipped: %d',
            $stats['created'],
            $stats['updated'],
            $stats['skipped']
        ));
    }
}
