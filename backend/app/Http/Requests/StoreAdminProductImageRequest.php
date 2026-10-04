<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAdminProductImageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'image' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'mimetypes:image/jpeg,image/png,image/webp', 'extensions:jpg,jpeg,png,webp', 'max:5120'],
            'image_type' => ['sometimes', 'required', Rule::in(['gallery', 'detail'])],
            'sort_order' => ['sometimes', 'required', 'integer', 'min:0', 'max:4294967295'],
            'is_primary' => ['sometimes', 'required', 'boolean'],
            'image_path' => ['missing'], 'image_url' => ['missing'], 'product_id' => ['missing'],
        ];
    }
}
