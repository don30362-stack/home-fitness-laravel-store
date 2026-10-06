<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

abstract class AdminRecommendedProductRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function withValidator(Validator $validator): void
    {
        $allowed = array_unique(array_map(fn ($field) => explode('.', $field)[0], array_keys($this->rules())));
        $validator->after(function (Validator $validator) use ($allowed) {
            foreach (array_diff(array_keys($this->all()), $allowed) as $field) {
                $validator->errors()->add($field, '不接受此欄位。');
            }
        });
    }
}
