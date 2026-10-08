<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class EnterAdminDemoRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return array_fill_keys(array_keys($this->all()), ['missing']);
    }
}
