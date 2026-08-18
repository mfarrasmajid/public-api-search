<?php

namespace App\Services;

use App\Models\Api;

/**
 * Turns metadata completeness + health into a 0..100 score.
 *
 * Weights mirror docs/quality-score.md. Treat them as a first guess: they are
 * meant to be re-tuned against real click data once the POC has traffic.
 */
class QualityScorer
{
    private const WEIGHTS = [
        'documentation' => 20,
        'availability' => 25,
        'https' => 10,
        'authentication' => 10,
        'openapi' => 10,
        'response_speed' => 10,
        'popularity' => 10,
        'freshness' => 5,
    ];

    public function score(Api $api): int
    {
        $api->loadMissing('latestHealthCheck');

        $points = 0.0;

        // Documentation: having a docs URL is the baseline, a description and
        // parsed endpoints show the metadata is actually rich.
        $documentation = 0.0;
        $documentation += $api->documentation_url ? 0.5 : 0.0;
        $documentation += filled($api->description) && mb_strlen((string) $api->description) > 40 ? 0.3 : 0.0;
        $documentation += $api->endpoints()->exists() ? 0.2 : 0.0;
        $points += $documentation * self::WEIGHTS['documentation'];

        // Availability: last known health check.
        $health = $api->latestHealthCheck?->status;
        $availability = match ($health) {
            'healthy' => 1.0,
            'degraded' => 0.6,
            'unhealthy' => 0.0,
            default => 0.5, // never checked - neutral, not a penalty
        };
        $points += $availability * self::WEIGHTS['availability'];

        $points += ($api->https ? 1.0 : 0.0) * self::WEIGHTS['https'];

        // Lower friction to first call scores higher.
        $points += match ($api->authentication_type) {
            'none' => 1.0,
            'apiKey' => 0.7,
            'bearer' => 0.6,
            'OAuth' => 0.4,
            default => 0.5,
        } * self::WEIGHTS['authentication'];

        $points += ($api->has_openapi ? 1.0 : 0.0) * self::WEIGHTS['openapi'];

        $responseTime = $api->latestHealthCheck?->response_time_ms;
        $points += match (true) {
            $responseTime === null => 0.5,
            $responseTime < 300 => 1.0,
            $responseTime < 800 => 0.7,
            $responseTime < 2000 => 0.4,
            default => 0.1,
        } * self::WEIGHTS['response_speed'];

        // Popularity has no real signal yet (no click data, no GitHub stars).
        // Kept neutral so it does not silently skew the ranking.
        $points += 0.5 * self::WEIGHTS['popularity'];

        $lastSeen = $api->last_seen_at ?? $api->updated_at;
        $points += match (true) {
            $lastSeen === null => 0.3,
            $lastSeen->gt(now()->subDays(30)) => 1.0,
            $lastSeen->gt(now()->subDays(180)) => 0.6,
            default => 0.2,
        } * self::WEIGHTS['freshness'];

        return (int) round(max(0, min(100, $points)));
    }
}
