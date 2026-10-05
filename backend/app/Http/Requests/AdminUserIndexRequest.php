<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdminUserIndexRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('search'))) {
            $this->merge(['search' => trim($this->input('search'))]);
        }
    }

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'string', Rule::in(['active', 'disabled', 'inactive'])],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
