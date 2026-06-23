<?php

namespace App\Http\Requests\Tenant;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class StatisticsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * GET form: return JSON 422 on validation failure instead of the default
     * redirect, mirroring ListAppointmentsRequest (the page itself only ever
     * sends valid dates via Inertia router.get).
     */
    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            response()->json(['message' => 'Ungültiger Zeitraum.', 'errors' => $validator->errors()], 422)
        );
    }

    /**
     * Reject an inverted range (from > to) — but only when BOTH bounds are
     * present, so a single-bound request keeps working (each defaults alone).
     */
    public function withValidator(\Illuminate\Validation\Validator $validator): void
    {
        $validator->after(function ($v) {
            $from = $this->input('from');
            $to = $this->input('to');
            if (is_string($from) && is_string($to) && $from !== '' && $to !== ''
                && strtotime($from) !== false && strtotime($to) !== false
                && strtotime($from) > strtotime($to)) {
                $v->errors()->add('from', 'Das Startdatum darf nicht nach dem Enddatum liegen.');
            }
        });
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ];
    }
}
