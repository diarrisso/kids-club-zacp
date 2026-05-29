<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AvailabilityException extends Model
{
    use HasFactory;

    protected $fillable = ['practitioner_id', 'starts_at', 'ends_at', 'type', 'reason'];

    protected $casts = ['starts_at' => 'datetime', 'ends_at' => 'datetime'];

    public function practitioner(): BelongsTo
    {
        return $this->belongsTo(Practitioner::class);
    }

    protected static function newFactory()
    {
        return \Database\Factories\Tenant\AvailabilityExceptionFactory::new();
    }
}
