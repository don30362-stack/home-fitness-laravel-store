<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminRecommendedProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $product = $this->product;
        $visible = $product !== null && $product->status === 'active' && $product->hasEffectiveCategory();
        $reason = null;
        if (! $product) {
            $reason = '商品目前不存在，不會顯示於前台推薦。';
        } elseif ($product->status !== 'active') {
            $reason = $product->status === 'disabled'
                ? '商品目前為停用狀態，不會顯示於前台推薦。' : '商品目前為下架狀態，不會顯示於前台推薦。';
        } else {
            $child = $product->category; $parent = $child?->parent;
            if (! $child || ! $parent || $child->parent_id === null || (int) $child->parent_id !== (int) $parent->id || $parent->parent_id !== null) {
                $reason = '商品分類結構目前無效，不會顯示於前台推薦。';
            } elseif ($child->status !== 'active') {
                $reason = '商品子分類目前停用，不會顯示於前台推薦。';
            } elseif ($parent->status !== 'active') {
                $reason = '商品主分類目前停用，不會顯示於前台推薦。';
            }
        }
        return [
            'id' => $this->id, 'product_id' => $this->product_id, 'sort_order' => $this->sort_order,
            'is_publicly_visible' => $visible, 'unavailable_reason' => $reason,
            'product' => $product ? new AdminProductListResource($product) : null,
        ];
    }
}
