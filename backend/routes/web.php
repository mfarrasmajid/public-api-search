<?php

use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json([
    'name' => 'Public API Discovery Engine',
    'docs' => '/api/search?q=weather',
    'health' => '/api/health',
]));
