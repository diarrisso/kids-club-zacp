<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Availability extends Model
{
    use HasFactory;

    protected $fillable = [
        'practitioner_id', 'day_of_week', 'start_time', 'end_time', 'valid_from', 'valid_to', 'slot_interval_minutes',
    ];

    protected $casts = [
        'start_time' => 'datetime:H:i',
        'end_time' => 'datetime:H:i',
        'valid_from' => 'date',
        'valid_to' => 'date',
        'slot_interval_minutes' => 'integer',
    ];

    public function practitioner(): BelongsTo
    {
        return $this->belongsTo(Practitioner::class);
    }

    protected static function newFactory()
    {
        return \Database\Factories\Tenant\AvailabilityFactory::new();
    }
}
