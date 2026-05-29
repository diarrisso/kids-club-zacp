<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Service extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'duration_minutes', 'color', 'description', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    protected $attributes = ['is_active' => true];

    public function practitioners(): BelongsToMany
    {
        return $this->belongsToMany(Practitioner::class);
    }

    protected static function newFactory()
    {
        return \Database\Factories\Tenant\ServiceFactory::new();
    }
}
