<?php

namespace App\Http\Requests\Tenant;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route protected by 'auth' middleware
    }

    public function rules(): array
    {
        return [
            'reminder_enabled'             => ['required', 'boolean'],
            'reminder_channel'             => ['required', 'string', 'in:email'],
            'reminder_lead_hours'          => ['required', 'integer', 'in:2,24,48'],
            'reminder_message'             => ['required', 'string', 'max:500'],
            'booking_confirmation_enabled' => ['required', 'boolean'],
            'notify_on_booking'            => ['required', 'boolean'],
            'notify_on_cancellation'       => ['required', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        // Inertia sends booleans as 1/0 or true/false strings from Vue.
        // Cast explicitly so the 'boolean' rule is satisfied without issues.
        $this->merge([
            'reminder_enabled'             => $this->toBoolean($this->reminder_enabled),
            'booking_confirmation_enabled' => $this->toBoolean($this->booking_confirmation_enabled),
            'notify_on_booking'            => $this->toBoolean($this->notify_on_booking),
            'notify_on_cancellation'       => $this->toBoolean($this->notify_on_cancellation),
        ]);
    }

    private function toBoolean(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? (bool) $value;
    }
}
