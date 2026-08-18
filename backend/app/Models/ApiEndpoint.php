<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApiEndpoint extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'parameters' => 'array',
            'request_schema' => 'array',
            'response_schema' => 'array',
            'example' => 'array',
        ];
    }

    public function api(): BelongsTo
    {
        return $this->belongsTo(Api::class);
    }
}
