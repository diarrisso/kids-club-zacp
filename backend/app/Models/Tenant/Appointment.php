<?php

namespace App\Models\Tenant;

use App\Support\Room;
use Database\Factories\Tenant\AppointmentFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Appointment extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'practitioner_id', 'service_id', 'starts_at', 'ends_at', 'status', 'room',
        'patient_first_name', 'patient_last_name', 'patient_birthdate',
        'parent_first_name', 'parent_last_name', 'parent_email', 'parent_phone',
        'parent_consent_at', 'notes_parent', 'cancellation_token',
        // notes_internal and reminder_sent_at are system/staff-only; never mass-assignable from the public API
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'patient_birthdate' => 'date',
        'parent_consent_at' => 'datetime',
        'reminder_sent_at' => 'datetime',
        'room' => Room::class,
    ];

    protected $attributes = ['status' => 'confirmed'];

    public function practitioner(): BelongsTo
    {
        return $this->belongsTo(Practitioner::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /**
     * Human-friendly booking reference, derived from the RANDOM TAIL of the
     * UUID v7 primary key — the prefix is a millisecond timestamp and must
     * not be used (same-window ids would all share it). ~24 bits of
     * randomness; collisions are tolerable for a display-only reference
     * (no lookup endpoint; the cabinet disambiguates by name+date). NOT the
     * cancellation_token secret — safe to show on screen, in emails, and to
     * quote on the phone.
     */
    public function publicReference(): string
    {
        return 'KC-'.strtoupper(substr(str_replace('-', '', (string) $this->id), -6));
    }

    protected static function newFactory()
    {
        return AppointmentFactory::new();
    }
}
