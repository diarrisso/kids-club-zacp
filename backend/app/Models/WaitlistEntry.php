<?php

namespace App\Models;

use App\Models\Tenant\Service;
use App\Support\WaitlistStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WaitlistEntry extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'patient_first_name', 'patient_last_name',
        'parent_first_name', 'parent_last_name',
        'parent_phone', 'parent_email',
        'service_id', 'notes',
        // status is intentionally NOT fillable — set by direct assignment (staff-only)
    ];

    protected function casts(): array
    {
        return [
            'status' => WaitlistStatus::class,
        ];
    }

    protected $attributes = ['status' => 'pending'];

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }
}
