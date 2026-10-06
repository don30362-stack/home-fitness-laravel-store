<?php

namespace App\Http\Requests;

class StoreManagedAdminRequest extends AdminManagementRequest
{
    public function rules(): array
    {
        return $this->strict(['name' => ['required', 'string', 'max:50'], 'email' => ['required', 'email', 'max:255', 'unique:admins,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'], 'password_confirmation' => ['required', 'string'], 'status' => ['sometimes', 'required', 'in:active,disabled']]);
    }
}
