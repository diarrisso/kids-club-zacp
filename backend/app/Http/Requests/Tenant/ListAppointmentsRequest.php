<?php

namespace App\Http\Requests\Tenant;

use App\Support\Attendance;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ListAppointmentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * GET requests with validation errors are normally redirected back (302) by
     * Laravel. For this search/filter endpoint we want a 422 JSON response so
     * tests (and future API consumers) can inspect the error bag directly.
     */
    protected function failedValidation(\Illuminate\Contracts\Validation\Validator $validator): never
    {
        throw new HttpResponseException(
            response()->json(['message' => 'The given data was invalid.', 'errors' => $validator->errors()], 422)
        );
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'attendance' => ['nullable', Rule::enum(Attendance::class)],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
