<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminUserDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'user' => new AdminUserResource($this->resource['user']),
            'orders' => AdminUserOrderSummaryResource::collection($this->resource['orders'])
                ->response()->getData(true),
        ];
    }
}
