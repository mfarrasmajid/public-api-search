<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Provider extends Model
{
    protected $guarded = [];

    public function apis(): HasMany
    {
        return $this->hasMany(Api::class);
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
