<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminProductListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product_code' => $this->product_code,
            'name' => $this->name,
            'category' => $this->category ? [
                'id' => $this->category->id,
                'name' => $this->category->name,
                'status' => $this->category->status,
            ] : null,
            'price' => number_format((float) $this->price, 2, '.', ''),
            'stock' => $this->stock === null ? null : (int) $this->stock,
            'has_variants' => (bool) $this->variants_exists,
            'low_stock_threshold' => (int) $this->low_stock_threshold,
            'status' => $this->status,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
