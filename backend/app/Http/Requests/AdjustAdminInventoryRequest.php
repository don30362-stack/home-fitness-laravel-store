<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AdjustAdminInventoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'adjustment' => ['required', 'integer', 'not_in:0', 'between:-4294967295,4294967295'],
            'stock' => ['missing'], 'new_stock' => ['missing'], 'quantity' => ['missing'],
        ];
    }

    public function messages(): array
    {
        return ['adjustment.not_in' => '增減數量不可為 0。'];
    }
}
