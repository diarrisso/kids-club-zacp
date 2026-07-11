<?php

namespace App\Http\Requests\Widget;

use Illuminate\Foundation\Http\FormRequest;

class StoreWaitlistRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Human-name pattern — see StoreAppointmentRequest::NAME_PATTERN. Blocks markdown
     * metacharacters so a waitlist name can't inject a phishing link into the
     * cabinet's markdown alert email.
     */
    private const NAME_PATTERN = "/^[\p{L}\p{M}\s.'’-]+$/u";

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
