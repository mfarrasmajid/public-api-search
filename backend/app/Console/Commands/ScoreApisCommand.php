<?php

namespace App\Console\Commands;

use App\Models\Api;
use App\Services\QualityScorer;
use Illuminate\Console\Command;

class ScoreApisCommand extends Command
{
    protected $signature = 'apis:score {--reindex : Reindex after scoring}';

    protected $description = 'Recompute the quality score of every API';

    public function handle(QualityScorer $scorer): int
    {
        $updated = 0;

        Api::query()->with('latestHealthCheck')->chunkById(200, function ($apis) use ($scorer, &$updated) {
            foreach ($apis as $api) {
                $score = $scorer->score($api);

                if ($score !== $api->quality_score) {
                    $api->forceFill(['quality_score' => $score])->saveQuietly();
                    $updated++;
                }
            }
        });

        $this->info("Updated quality score for {$updated} APIs.");

        if ($this->option('reindex')) {
            $this->call('search:reindex');
        }

        return self::SUCCESS;
    }
}
