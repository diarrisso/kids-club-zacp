<?php

namespace App\Http\Requests\Tenant;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

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
            // max:7 + distinct: a week has 7 weekdays — cap length and reject duplicates so a
            // crafted payload (e.g. days:[1,1,1,…]) can't loop the controller into N duplicate rows.
            'days' => $modeB ? ['nullable', 'array'] : ['required', 'array', 'min:1', 'max:7'],
            'days.*' => ['integer', 'between:1,7', 'distinct'],
            'start_time' => $modeB ? ['nullable'] : ['required', 'date_format:H:i'],
            'end_time' => $modeB ? ['nullable'] : ['required', 'date_format:H:i', 'after:start_time'],
            'days_hours' => $modeB ? ['required', 'array', 'min:1', 'max:7'] : ['nullable', 'array'],
            'days_hours.*.start' => ['required_with:days_hours', 'date_format:H:i'],
            'days_hours.*.end' => ['required_with:days_hours', 'date_format:H:i'],
            'valid_from' => ['required', 'date', 'after_or_equal:today'],
            'valid_to' => ['nullable', 'date', 'after:valid_from'],
            'slot_interval_minutes' => ['nullable', 'integer', 'in:20,30'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function ($validator) {
            foreach ((array) $this->input('days_hours', []) as $day => $hours) {
                if (! is_numeric($day) || (int) $day < 1 || (int) $day > 7) {
                    $validator->errors()->add("days_hours.{$day}", 'Der Wochentag muss zwischen 1 und 7 liegen.');

                    continue;
                }
                $start = $hours['start'] ?? null;
                $end = $hours['end'] ?? null;
                if ($start !== null && $end !== null && $end <= $start) {
                    $validator->errors()->add("days_hours.{$day}.end", 'Das Ende muss nach dem Beginn liegen.');
                }
            }
        });
    }
}
