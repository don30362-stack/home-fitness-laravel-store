<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

abstract class AdminManagementRequest extends FormRequest
{
    private array $allowedFields = [];

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        foreach (['name', 'email'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => trim($this->input($field))]);
            }
        }
    }

    protected function strict(array $rules): array
    {
        $this->allowedFields = array_unique(array_map(fn ($field) => explode('.', $field)[0], array_keys($rules)));

        return $rules;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            // Compare literal keys: dotted/wildcard input must not bypass strict contracts.
            foreach (array_diff(array_keys($this->all()), $this->allowedFields) as $field) {
                $validator->errors()->add($field, '不接受此欄位。');
            }
        });
    }
}
