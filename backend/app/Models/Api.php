<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Api extends Model
{
    use HasFactory;

    protected $table = 'apis';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'https' => 'boolean',
            'has_openapi' => 'boolean',
            'tags' => 'array',
            'quality_score' => 'integer',
            'last_checked_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'indexed_at' => 'datetime',
        ];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function endpoints(): HasMany
    {
        return $this->hasMany(ApiEndpoint::class);
    }

    public function healthChecks(): HasMany
    {
        return $this->hasMany(ApiHealthCheck::class);
    }

    public function latestHealthCheck(): HasOne
    {
        return $this->hasOne(ApiHealthCheck::class)->latestOfMany('checked_at');
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * Flat document shipped to OpenSearch. Everything the ranking needs must
     * live here - the search path never joins back to PostgreSQL.
     */
    public function toSearchDocument(): array
    {
        $this->loadMissing(['provider', 'category', 'endpoints', 'latestHealthCheck']);

        $endpointsText = $this->endpoints
            ->map(fn (ApiEndpoint $e) => trim($e->method.' '.$e->path.' '.$e->description))
            ->implode("\n");

        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'category' => $this->category?->name,
            'provider' => $this->provider?->name,
            'tags' => $this->tags ?? [],
            'website' => $this->website,
            'documentation_url' => $this->documentation_url,
            'base_url' => $this->base_url,
            'authentication_type' => $this->authentication_type,
            'https' => (bool) $this->https,
            'cors' => $this->cors,
            'country' => $this->country,
            'status' => $this->status,
            'license' => $this->license,
            'has_openapi' => (bool) $this->has_openapi,
            'quality_score' => (int) $this->quality_score,
            'endpoint_count' => $this->endpoints->count(),
            'endpoints_text' => $endpointsText,
            'health_status' => $this->latestHealthCheck?->status ?? 'unknown',
            'response_time_ms' => $this->latestHealthCheck?->response_time_ms,
            'last_checked_at' => optional($this->last_checked_at)->toIso8601String(),
            'updated_at' => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
