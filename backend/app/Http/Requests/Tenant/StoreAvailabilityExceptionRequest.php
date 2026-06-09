<?php

namespace App\Http\Requests\Tenant;

use Illuminate\Foundation\Http\FormRequest;

class StoreAvailabilityExceptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $isCabinet = $this->boolean('is_cabinet_closure');

        return [
            'is_cabinet_closure' => ['boolean'],
            'practitioner_id' => $isCabinet ? ['nullable'] : ['required', 'exists:practitioners,id'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'type' => $isCabinet ? ['nullable'] : ['required', 'in:vacation,sick,block,cabinet_closure'],
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }
}
