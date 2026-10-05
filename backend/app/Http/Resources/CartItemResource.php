<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CartItemResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $primaryImage = $this->product->images
            ->firstWhere('is_primary', true)
            ?? $this->product->images->first();

        $availableStock = $this->product_variant_id !== null
            ? (int) ($this->productVariant?->stock ?? 0)
            : (int) ($this->product->stock ?? 0);

        $unavailableReason = \App\Support\CartAvailability::reason($this->resource);

        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'product_variant_id' => $this->product_variant_id,

            'product' => [
                'id' => $this->product->id,
                'product_code' => $this->product->product_code,
                'name' => $this->product->name,
                'price' => $this->product->price,
                'status' => $this->product->status,
                'primary_image' => $primaryImage
                    ? new ProductImageResource($primaryImage)
                    : null,
            ],

            'variant' => $this->productVariant
                ? new ProductVariantResource($this->productVariant)
                : null,

            'quantity' => $this->quantity,
            'unit_price' => $this->product->price,
            'subtotal' => number_format(
                (float) $this->product->price * $this->quantity,
                2,
                '.',
                ''
            ),
            'available_stock' => $availableStock,
            'is_available' => $unavailableReason === null,
            'unavailable_reason' => $unavailableReason,
        ];
    }

}
