<?php

namespace App\Http\Requests\Tenant;

use Illuminate\Foundation\Http\FormRequest;

class BulkStoreAvailabilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $modeB = $this->has('days_hours');

        return [
            'practitioner_id' => ['required', 'exists:practitioners,id'],
            'days' => $modeB ? ['nullable', 'array'] : ['required', 'array', 'min:1'],
            'days.*' => ['integer', 'between:1,7'],
            'start_time' => $modeB ? ['nullable'] : ['required', 'date_format:H:i'],
            'end_time' => $modeB ? ['nullable'] : ['required', 'date_format:H:i', 'after:start_time'],
            'days_hours' => $modeB ? ['required', 'array', 'min:1'] : ['nullable', 'array'],
            'days_hours.*.start' => ['required_with:days_hours', 'date_format:H:i'],
            'days_hours.*.end' => ['required_with:days_hours', 'date_format:H:i'],
            'valid_from' => ['required', 'date', 'after_or_equal:today'],
            'valid_to' => ['nullable', 'date', 'after:valid_from'],
            'slot_interval_minutes' => ['nullable', 'integer', 'in:20,30'],
        ];
    }
}
