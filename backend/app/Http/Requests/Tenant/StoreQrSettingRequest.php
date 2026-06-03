<?php

namespace App\Http\Requests\Tenant;

use Illuminate\Foundation\Http\FormRequest;

class StoreQrSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route protégée par le middleware 'auth'
    }

    public function rules(): array
    {
        return [
            'booking_url' => ['required', 'url:http,https', 'max:2048'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->booking_url)) {
            $this->merge(['booking_url' => trim($this->booking_url)]);
        }
    }
}
