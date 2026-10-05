<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAdminUserStatusRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        $rules = ['status' => ['required', 'string', Rule::in(['active', 'disabled'])]];
        foreach (array_diff(array_keys($this->all()), array_keys($rules)) as $field) {
            $rules[$field] = ['missing'];
        }
        return $rules;
    }
}
