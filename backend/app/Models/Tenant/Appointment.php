<?php
namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Appointment extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'practitioner_id', 'service_id', 'starts_at', 'ends_at', 'status',
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

    protected static function newFactory()
    {
        return \Database\Factories\Tenant\AppointmentFactory::new();
    }
}
