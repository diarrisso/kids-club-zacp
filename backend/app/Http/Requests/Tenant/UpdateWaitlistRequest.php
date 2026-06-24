<?php

namespace App\Http\Requests\Tenant;

use App\Support\WaitlistStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateWaitlistRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(WaitlistStatus::class)],
        ];
    }
}
