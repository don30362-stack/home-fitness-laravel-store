<?php

namespace App\Http\Resources;

use App\Models\Permission;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $demo = app(\App\Services\AdminDemoService::class)->isDemo($this->resource);
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'status' => $this->status,
            'permissions' => $demo ? [] : $this->resource->getRelation('permissions')
                ->whereIn('code', array_keys(Permission::CATALOG))
                ->pluck('code')->sort()->values()->all(),
        ] + ($demo ? ['is_demo' => true] : []);
    }
}
