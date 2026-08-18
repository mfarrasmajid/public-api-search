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
        ];

        foreach ($sources as $source) {
            CrawlSource::updateOrCreate(['slug' => $source['slug']], $source);
        }

        $this->command?->info('Crawl sources seeded: '.count($sources));
    }
}
