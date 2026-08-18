<?php

namespace App\Providers;

use App\Services\Indexing\ApiIndexer;
use App\Services\Indexing\IndexManager;
use App\Services\OpenSearchClientFactory;
use App\Services\Search\DatabaseSearchDriver;
use App\Services\Search\OpenSearchDriver;
use App\Services\Search\SearchService;
use Illuminate\Support\ServiceProvider;
use OpenSearch\Client;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Client::class, fn () => OpenSearchClientFactory::make(config('opensearch')));

        $this->app->singleton(IndexManager::class, fn ($app) => new IndexManager(
            $app->make(Client::class),
            config('opensearch.alias')
        ));

        $this->app->singleton(ApiIndexer::class, fn ($app) => new ApiIndexer(
            $app->make(Client::class),
            $app->make(IndexManager::class),
            (int) config('opensearch.bulk_size')
        ));

        $this->app->singleton(OpenSearchDriver::class, fn ($app) => new OpenSearchDriver(
            $app->make(Client::class),
            config('opensearch.alias'),
            config('opensearch.search')
        ));

        $this->app->singleton(SearchService::class, fn ($app) => new SearchService(
            primary: $app->make(OpenSearchDriver::class),
            fallback: $app->make(DatabaseSearchDriver::class),
            fallbackEnabled: (bool) config('opensearch.fallback_to_database'),
        ));
    }

    public function boot(): void
    {
        //
    }
}
