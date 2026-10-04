<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminInventoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'stock_owner_type' => $this->stock_owner_type,
            'stock_owner_id' => (int) $this->stock_owner_id,
            'product_id' => (int) $this->product_id,
            'product_code' => $this->product_code,
            'product_name' => $this->product_name,
            'product_status' => $this->product_status,
            'category' => ['id' => (int) $this->category_id, 'name' => $this->category_name],
            'variant' => $this->variant_id === null ? null : [
                'id' => (int) $this->variant_id, 'option_name' => $this->option_name,
                'option_value' => $this->option_value, 'status' => $this->variant_status,
            ],
            'stock' => (int) $this->stock,
            'low_stock_threshold' => (int) $this->low_stock_threshold,
            'inventory_status' => $this->inventory_status,
        ];
    }
}
