<?php

namespace App\Http\Requests;

class UpdateManagedAdminStatusRequest extends AdminManagementRequest
{
    public function rules(): array
    {
        return $this->strict(['status' => ['required', 'in:active,disabled']]);
    }
}
