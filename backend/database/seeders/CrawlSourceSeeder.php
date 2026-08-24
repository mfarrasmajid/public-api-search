<?php

namespace Database\Seeders;

use App\Models\CrawlSource;
use Illuminate\Database\Seeder;

/**
 * Sources the Python crawler reads in phase 2. Rate limits are deliberately
 * conservative - see docs/security-and-legal.md.
 */
class CrawlSourceSeeder extends Seeder
{
    public function run(): void
    {
        $sources = [
            [
                'name' => 'public-apis (GitHub)',
                'slug' => 'public-apis-github',
                'type' => 'directory',
                'url' => 'https://raw.githubusercontent.com/public-apis/public-apis/master/README.md',
                'rate_limit_per_minute' => 10,
                'config' => ['parser' => 'public_apis_markdown'],
            ],
            [
                'name' => 'APIs.guru directory',
                'slug' => 'apis-guru',
                'type' => 'openapi',
                'url' => 'https://api.apis.guru/v2/list.json',
                'rate_limit_per_minute' => 20,
                'config' => ['parser' => 'apis_guru'],
            ],
            [
                // Satu Data Indonesia. CKAN portals are paginated, so the rate
                // limit is deliberately low: a full crawl is many requests to
                // one government host.
                'name' => 'Satu Data Indonesia (data.go.id)',
                'slug' => 'data-go-id',
                'type' => 'directory',
                'url' => 'https://data.go.id/api/3/action/package_search',
                'rate_limit_per_minute' => 10,
                'config' => ['parser' => 'ckan', 'portal_url' => 'https://data.go.id', 'country' => 'Indonesia'],
            ],
            [
                'name' => 'Open Data Jakarta (data.jakarta.go.id)',
                'slug' => 'data-jakarta',
                'type' => 'directory',
                'url' => 'https://data.jakarta.go.id/api/3/action/package_search',
                'rate_limit_per_minute' => 10,
                'config' => ['parser' => 'ckan', 'portal_url' => 'https://data.jakarta.go.id', 'country' => 'Indonesia'],
            ],
        ];

        foreach ($sources as $source) {
            CrawlSource::updateOrCreate(['slug' => $source['slug']], $source);
        }

        $this->command?->info('Crawl sources seeded: '.count($sources));
    }
}
