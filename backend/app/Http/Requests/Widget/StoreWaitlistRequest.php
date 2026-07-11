<?php

namespace App\Http\Requests\Widget;

use App\Http\Requests\Widget\Concerns\ValidatesHumanNames;
use Illuminate\Foundation\Http\FormRequest;

class StoreWaitlistRequest extends FormRequest
{
    use ValidatesHumanNames;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'patient_first_name' => ['required', 'string', 'max:255', 'regex:'.self::NAME_PATTERN],
            'patient_last_name' => ['required', 'string', 'max:255', 'regex:'.self::NAME_PATTERN],
            'parent_first_name' => ['required', 'string', 'max:255', 'regex:'.self::NAME_PATTERN],
            'parent_last_name' => ['required', 'string', 'max:255', 'regex:'.self::NAME_PATTERN],
            'parent_phone' => ['required', 'string', 'max:255'],
            'parent_email' => ['nullable', 'email', 'max:255'],
            'service_id' => ['nullable', 'integer', 'exists:services,id'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'consent' => ['accepted'],
            'website' => ['nullable', 'string'],
        ];
    }
}
