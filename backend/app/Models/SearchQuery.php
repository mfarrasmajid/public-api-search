<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SearchQuery extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'filters' => 'array',
        ];
    }
}
