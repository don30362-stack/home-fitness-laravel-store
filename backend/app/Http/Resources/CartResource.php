<?php

namespace App\Http\Resources;

use App\Models\CartItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CartResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $items = $this->items;

        return [
            'id' => $this->id,
            'items' => CartItemResource::collection($items),

            'item_count' => (int) $items->sum('quantity'),

            'subtotal' => number_format(
                $items->sum(
                    fn(CartItem $item) =>
                    (float) $item->product->price
                        * $item->quantity
                ),
                2,
                '.',
                ''
            ),

            'has_unavailable_items' => $items->contains(
                fn(CartItem $item) =>
                \App\Support\CartAvailability::reason($item) !== null
            ),
        ];
    }

}
