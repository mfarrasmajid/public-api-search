<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApiHealthCheck extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'dns_ok' => 'boolean',
            'tls_ok' => 'boolean',
            'tls_expires_at' => 'datetime',
            'checked_at' => 'datetime',
        ];
    }

    public function api(): BelongsTo
    {
        return $this->belongsTo(Api::class);
    }
}
