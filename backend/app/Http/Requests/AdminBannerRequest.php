<?php

namespace App\Http\Requests;

use App\Rules\InternalPath;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

abstract class AdminBannerRequest extends FormRequest
{
    private array $allowedTopFields = [];

    public function authorize(): bool { return true; }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            // Literal dotted/wildcard keys must not escape Laravel's nested rule notation.
            foreach (array_diff(array_keys($this->all()), $this->allowedTopFields) as $field) {
                $validator->errors()->add($field, '不接受此欄位。');
            }
        });
    }

    protected function prepareForValidation(): void
    {
        $values = [];
        foreach (['title', 'subtitle', 'button_text', 'link_url'] as $field) {
            if ($this->exists($field) && is_string($this->input($field))) {
                // Do not trim away ASCII controls from links before security validation.
                $value = trim($this->input($field), $field === 'link_url' ? ' ' : " \t\n\r\0\x0B");
                $values[$field] = $value === '' ? null : $value;
            }
        }
        $this->merge($values);
    }

    protected function bannerRules(bool $creating): array
    {
        $rules = [
            'title' => [$creating ? 'required' : 'sometimes', 'required', 'string', 'max:150'],
            'subtitle' => ['sometimes', 'nullable', 'string', 'max:255'],
            'button_text' => ['sometimes', 'nullable', 'string', 'max:50'],
            'link_url' => ['sometimes', 'nullable', 'string', 'max:255', new InternalPath],
            'sort_order' => ['sometimes', 'required', 'integer', 'min:0', 'max:4294967295'],
            'image' => [$creating ? 'required' : 'sometimes', 'required', 'file', 'image',
                'mimes:jpg,jpeg,png,webp', 'mimetypes:image/jpeg,image/png,image/webp', 'extensions:jpg,jpeg,png,webp', 'max:5120'],
        ];
        if ($creating) {
            $rules['status'] = ['sometimes', 'required', 'string', 'in:active,inactive'];
        }
        return $this->strictRules($rules, ! $creating);
    }

    protected function strictRules(array $rules, bool $transportMetadata = false): array
    {
        $this->allowedTopFields = [...array_unique(array_map(fn ($field) => explode('.', $field)[0], array_keys($rules))), ...($transportMetadata ? ['_method'] : [])];
        return $rules;
    }
}
