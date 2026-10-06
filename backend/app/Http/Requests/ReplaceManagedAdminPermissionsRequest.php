<?php

namespace App\Http\Requests;

use App\Models\Permission;
use Illuminate\Validation\Rule;

class ReplaceManagedAdminPermissionsRequest extends AdminManagementRequest
{
    public function rules(): array
    {
        return $this->strict(['permission_ids' => ['present', 'array', 'list'], 'permission_ids.*' => ['required', 'integer', 'distinct', Rule::exists('permissions', 'id')->whereIn('code', array_keys(Permission::CATALOG))]]);
    }
}
