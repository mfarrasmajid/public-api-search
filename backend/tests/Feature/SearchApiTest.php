<?php

namespace Tests\Feature;

use App\Models\Api;
use App\Models\Category;
use App\Services\ApiImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * These tests run against the database fallback driver (no cluster needed in
 * CI). Relevance/fuzziness behaviour is verified separately in
 * tests/Feature/OpenSearchRelevanceTest.php, which is skipped unless
 * OpenSearch is reachable.
 */
class SearchApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(ApiImporter::class)->import([
            [
                'name' => 'Example Weather API',
                'description' => 'Weather forecast and current conditions worldwide.',
                'category' => 'Weather',
                'provider' => 'Example Inc',
                'documentation_url' => 'https://example.com/docs',
                'authentication_type' => 'none',
                'https' => true,
                'cors' => 'yes',
                'country' => 'Global',
                'tags' => ['weather', 'cuaca', 'forecast'],
            ],
            [
                'name' => 'Example Payment API',
                'description' => 'Payment gateway for Indonesian merchants.',
                'category' => 'Finance',
                'provider' => 'Bayar Co',
                'authentication_type' => 'apiKey',
                'https' => true,
                'cors' => 'no',
                'country' => 'Indonesia',
                'tags' => ['payment', 'pembayaran'],
            ],
        ], 'test');
    }

    public function test_search_returns_the_documented_contract(): void
    {
        $response = $this->getJson('/api/search?q=weather');

        $response->assertOk()
            ->assertJsonStructure([
                'query', 'total', 'page', 'per_page', 'took_ms', 'driver', 'filters', 'facets',
                'results' => [['name', 'slug', 'score', 'category', 'authentication', 'https', 'quality_score']],
            ])
            ->assertJsonPath('query', 'weather');

        $this->assertSame('Example Weather API', $response->json('results.0.name'));
    }

    public function test_search_can_filter_by_authentication_and_https(): void
    {
        $response = $this->getJson('/api/search?q=example&auth=none&https=1');

        $response->assertOk();
        $this->assertSame(1, $response->json('total'));
        $this->assertSame('Example Weather API', $response->json('results.0.name'));
    }

    public function test_search_records_telemetry(): void
    {
        $this->getJson('/api/search?q=gempa');

        $this->assertDatabaseHas('search_queries', ['query' => 'gempa']);
    }

    public function test_search_rejects_invalid_filters(): void
    {
        $this->getJson('/api/search?q=weather&auth=magic')
            ->assertStatus(422);
    }

    public function test_api_detail_is_available_by_slug(): void
    {
        $api = Api::where('slug', 'example-weather-api')->firstOrFail();

        $this->getJson("/api/apis/{$api->slug}")
            ->assertOk()
            ->assertJsonPath('data.name', 'Example Weather API')
            ->assertJsonPath('data.documentation_url', 'https://example.com/docs');
    }

    public function test_meta_endpoint_exposes_filter_options(): void
    {
        $this->getJson('/api/meta')
            ->assertOk()
            ->assertJsonPath('total_apis', 2);

        $this->assertSame(2, Category::count());
    }
}
