<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

// Mutation 是單一分類，不把 child 假裝成管理樹的 root。
class AdminCategoryMutationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'parent_id' => $this->parent_id,
            'name' => $this->name,
            'status' => $this->status,
            'sort_order' => (int) $this->sort_order,
            'children_count' => (int) $this->children_count,
            'product_count' => (int) $this->product_count,
            'children' => AdminCategoryChildResource::collection($this->whenLoaded('children')),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
