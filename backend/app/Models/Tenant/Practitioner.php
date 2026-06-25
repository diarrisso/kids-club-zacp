<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Practitioner extends Model
{
    use HasFactory;

    protected $fillable = [
        'first_name', 'last_name', 'title', 'email', 'avatar_url', 'color', 'is_active',
    ];

    protected $casts = ['is_active' => 'boolean'];

    protected $attributes = ['is_active' => true];

    public function fullName(): string
    {
        return trim("{$this->title} {$this->first_name} {$this->last_name}");
    }

    public function getNameAttribute(): string
    {
        return $this->fullName();
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }

    public function services(): BelongsToMany
    {
        return $this->belongsToMany(Service::class);
    }

    public function availabilities(): HasMany
    {
        return $this->hasMany(Availability::class);
    }

    public function availabilityExceptions(): HasMany
    {
        return $this->hasMany(AvailabilityException::class);
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    protected static function newFactory()
    {
        return \Database\Factories\Tenant\PractitionerFactory::new();
    }
}
