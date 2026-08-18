<?php

namespace Tests\Feature;

use App\Services\ApiImporter;
use App\Services\Indexing\ApiIndexer;
use App\Services\Search\OpenSearchDriver;
use App\Services\Search\SearchQueryData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use OpenSearch\Client;
use Tests\TestCase;

/**
 * Relevance contract of the POC (definition of done #5 and #6).
 *
 * Skipped automatically when no cluster is reachable, so `php artisan test`
 * stays green on a laptop with only PHP installed. Run it inside compose:
 *   docker compose exec backend php artisan test --filter=OpenSearchRelevance
 */
class OpenSearchRelevanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'opensearch.host' => env('OPENSEARCH_TEST_HOST', 'opensearch'),
            'opensearch.port' => (int) env('OPENSEARCH_TEST_PORT', 9200),
            'opensearch.alias' => 'apis_test',
        ]);

        try {
            app(Client::class)->cluster()->health();
        } catch (\Throwable $e) {
            $this->markTestSkipped('OpenSearch not reachable: '.$e->getMessage());
        }

        app(ApiImporter::class)->import([
            ['name' => 'Weather Forecast API', 'description' => 'Hourly weather forecast worldwide.', 'category' => 'Weather', 'tags' => ['weather', 'cuaca', 'forecast']],
            ['name' => 'BMKG Gempa', 'description' => 'Earthquake data for Indonesia.', 'category' => 'Government', 'country' => 'Indonesia', 'tags' => ['gempa', 'earthquake']],
            ['name' => 'Payment Gateway API', 'description' => 'Charge cards and e-wallets.', 'category' => 'Finance', 'tags' => ['payment']],
        ], 'test');

        app(ApiIndexer::class)->rebuild();
    }

    public function test_keyword_query_ranks_the_matching_api_first(): void
    {
        $result = app(OpenSearchDriver::class)->search(new SearchQueryData(query: 'weather'));

        $this->assertGreaterThan(0, $result->total);
        $this->assertSame('Weather Forecast API', $result->hits[0]['name']);
    }

    public function test_typos_still_return_the_relevant_api(): void
    {
        $result = app(OpenSearchDriver::class)->search(new SearchQueryData(query: 'wether forecats'));

        $this->assertGreaterThan(0, $result->total);
        $this->assertSame('Weather Forecast API', $result->hits[0]['name']);
    }

    public function test_indonesian_query_finds_the_english_document(): void
    {
        $result = app(OpenSearchDriver::class)->search(new SearchQueryData(query: 'cuaca'));

        $this->assertGreaterThan(0, $result->total);
        $this->assertSame('Weather Forecast API', $result->hits[0]['name']);
    }

    public function test_facets_are_returned_for_the_filter_sidebar(): void
    {
        $result = app(OpenSearchDriver::class)->search(new SearchQueryData(query: ''));

        $this->assertArrayHasKey('categories', $result->facets);
        $this->assertNotEmpty($result->facets['categories']);
    }
}
