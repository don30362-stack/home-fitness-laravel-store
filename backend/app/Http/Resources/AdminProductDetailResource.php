<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class AdminProductDetailResource extends AdminProductListResource
{
    public function toArray(Request $request): array
    {
        $data = parent::toArray($request);
        if ($data['category'] !== null) {
            $parent = $this->category->parent;
            $data['category']['parent'] = $parent ? [
                'id' => $parent->id, 'name' => $parent->name, 'status' => $parent->status,
            ] : null;
        }

        return array_merge($data, [
            'short_description' => $this->short_description,
            'description' => $this->description,
            'specifications' => ProductSpecificationResource::collection($this->whenLoaded('specifications')),
            'images' => ProductImageResource::collection($this->whenLoaded('images')),
            'variants' => ProductVariantResource::collection($this->whenLoaded('variants')),
        ]);
    }
}
