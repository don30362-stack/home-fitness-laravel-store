<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class UpdateManagedAdminRequest extends AdminManagementRequest
{
    public function rules(): array
    {
        return $this->strict(['name' => ['sometimes', 'required', 'string', 'max:50'], 'email' => ['sometimes', 'required', 'email', 'max:255', Rule::unique('admins', 'email')->ignore($this->route('id'))]]);
    }
}
