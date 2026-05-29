<?php
namespace Database\Factories\Tenant;

use App\Models\Tenant\Appointment;
use App\Models\Tenant\Practitioner;
use App\Models\Tenant\Service;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class AppointmentFactory extends Factory
{
    protected $model = Appointment::class;

    public function definition(): array
    {
        return [
            'practitioner_id' => Practitioner::factory(),
            'service_id' => Service::factory(),
            'starts_at' => '2026-09-01 09:00:00',
            'ends_at' => '2026-09-01 09:30:00',
            'status' => 'confirmed',
            'patient_first_name' => 'Lina',
            'patient_last_name' => 'Müller',
            'patient_birthdate' => '2019-04-12',
            'parent_first_name' => 'Anna',
            'parent_last_name' => 'Müller',
            'parent_email' => 'anna@example.de',
            'parent_phone' => '+49 170 0000000',
            'parent_consent_at' => now(),
            'cancellation_token' => (string) Str::uuid(),
        ];
    }
}
