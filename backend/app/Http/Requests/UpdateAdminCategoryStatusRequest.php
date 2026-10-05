<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAdminCategoryStatusRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        $rules = ['status' => ['required', 'string', 'in:active,inactive']];
        foreach (array_diff(array_keys($this->all()), ['status']) as $field) {
            $rules[$field] = ['missing'];
        }
        return $rules;
    }
}
