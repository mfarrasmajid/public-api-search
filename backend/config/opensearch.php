<?php

return [

    /*
    |--------------------------------------------------------------------------
    | OpenSearch connection
    |--------------------------------------------------------------------------
    |
    | For the local POC the security plugin is disabled, so username/password
    | are optional. Keep them here so the same code can talk to a secured
    | cluster on a VPS without changes.
    |
    */

    'host' => env('OPENSEARCH_HOST', 'opensearch'),
    'port' => (int) env('OPENSEARCH_PORT', 9200),
    'scheme' => env('OPENSEARCH_SCHEME', 'http'),
    'username' => env('OPENSEARCH_USERNAME'),
    'password' => env('OPENSEARCH_PASSWORD'),
    'verify_ssl' => filter_var(env('OPENSEARCH_VERIFY_SSL', false), FILTER_VALIDATE_BOOL),

    /*
    |--------------------------------------------------------------------------
    | Index
    |--------------------------------------------------------------------------
    |
    | "alias" is what the application queries. Reindexing builds a new
    | timestamped index and flips the alias, so search never goes dark.
    |
    */

    'alias' => env('OPENSEARCH_INDEX', 'apis'),

    'bulk_size' => (int) env('OPENSEARCH_BULK_SIZE', 250),

    /*
    |--------------------------------------------------------------------------
    | Search behaviour
    |--------------------------------------------------------------------------
    |
    | Field boosts decide ranking. Tune these while looking at real queries;
    | they are the cheapest relevance lever you have before semantic search.
    |
    */

    'search' => [
        'fields' => [
            'name^6',
            'name.folded^4',
            'tags^4',
            'category^3',
            'provider^2',
            'description^2',
            'description.folded',
            'endpoints_text',
        ],
        'fuzziness' => env('OPENSEARCH_FUZZINESS', 'AUTO'),
        'min_score' => (float) env('OPENSEARCH_MIN_SCORE', 0.1),
        'max_size' => 50,
    ],

    /*
    |--------------------------------------------------------------------------
    | Fallback
    |--------------------------------------------------------------------------
    |
    | When true, a failing/unreachable OpenSearch falls back to a plain
    | PostgreSQL ILIKE search so the UI keeps working (degraded ranking).
    |
    */

    'fallback_to_database' => filter_var(env('SEARCH_FALLBACK_TO_DATABASE', true), FILTER_VALIDATE_BOOL),
];
