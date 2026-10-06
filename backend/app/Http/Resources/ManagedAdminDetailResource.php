<?php

namespace App\Http\Resources;

use App\Models\Permission;
use Illuminate\Http\Request;

class ManagedAdminDetailResource extends ManagedAdminResource
{
    public function toArray(Request $request): array
    {
        return parent::toArray($request) + ['created_at' => $this->created_at?->toISOString(), 'updated_at' => $this->updated_at?->toISOString(),
            'permissions' => $this->resource->getRelation('permissions')->whereIn('code', array_keys(Permission::CATALOG))->sortBy('code')->values()->map(fn ($p) => ['id' => $p->id, 'code' => $p->code, 'name' => $p->name])->all()];
    }
}
