<?php

namespace Tests\Unit;

use App\Models\Api;
use App\Models\ApiHealthCheck;
use App\Services\QualityScorer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QualityScorerTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_well_documented_healthy_api_scores_higher_than_a_bare_one(): void
    {
        $scorer = app(QualityScorer::class);

        $good = Api::create([
            'name' => 'Good API',
            'slug' => 'good-api',
            'description' => 'A well documented API with a long, useful description for search.',
            'documentation_url' => 'https://example.com/docs',
            'authentication_type' => 'none',
            'https' => true,
            'has_openapi' => true,
            'last_seen_at' => now(),
        ]);

        ApiHealthCheck::create([
            'api_id' => $good->id,
            'status' => 'healthy',
            'http_status' => 200,
            'response_time_ms' => 120,
            'dns_ok' => true,
            'tls_ok' => true,
            'checked_at' => now(),
        ]);

        $bare = Api::create([
            'name' => 'Bare API',
            'slug' => 'bare-api',
            'authentication_type' => 'OAuth',
            'https' => false,
            'last_seen_at' => now()->subYear(),
        ]);

        $this->assertGreaterThan($scorer->score($bare), $scorer->score($good->fresh()));
        $this->assertLessThanOrEqual(100, $scorer->score($good->fresh()));
        $this->assertGreaterThanOrEqual(0, $scorer->score($bare));
    }
}
