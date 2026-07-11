<?php

namespace App\Http\Requests\Widget;

use Illuminate\Foundation\Http\FormRequest;

class StoreAppointmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Human-name pattern: Unicode letters + accent marks, spaces, apostrophes,
     * hyphens, periods (Müller, Jean-Pierre, O'Brien, José). It blocks the markdown
     * metacharacters [ ] ( ) * _ ` # so a name can't smuggle a clickable phishing
     * link into the cabinet's markdown alert emails (rendered through CommonMark).
     */
    private const NAME_PATTERN = "/^[\p{L}\p{M}\s.'’-]+$/u";

    public function rules(): array
    {
        return [
            'practitioner_id' => ['required', 'exists:practitioners,id'],
            'service_id' => ['required', 'exists:services,id'],
            'starts_at' => ['required', 'date', 'after:now', 'before:'.now()->addDays(61)->toDateString()],
            'patient_first_name' => ['required', 'string', 'max:255', 'regex:'.self::NAME_PATTERN],
            'patient_last_name' => ['required', 'string', 'max:255', 'regex:'.self::NAME_PATTERN],
            'patient_birthdate' => ['required', 'date', 'before:today'],
            'parent_first_name' => ['required', 'string', 'max:255', 'regex:'.self::NAME_PATTERN],
            'parent_last_name' => ['required', 'string', 'max:255', 'regex:'.self::NAME_PATTERN],
            'parent_email' => ['required', 'email', 'max:255'],
            'parent_phone' => ['nullable', 'string', 'max:50'],
            'notes_parent' => ['nullable', 'string', 'max:2000'],
            'consent' => ['accepted'],
            'website' => ['nullable', 'string'],
            'room' => ['nullable', 'in:green,yellow,peach,blue,purple'],
        ];
    }
}
