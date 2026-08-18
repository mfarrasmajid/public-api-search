<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CrawlSource extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'respect_robots_txt' => 'boolean',
            'config' => 'array',
            'last_run_at' => 'datetime',
        ];
    }

    public function jobs(): HasMany
    {
        return $this->hasMany(CrawlJob::class);
    }
}
