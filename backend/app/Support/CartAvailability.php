<?php

namespace App\Support;

use App\Models\CartItem;

class CartAvailability
{
    public static function reason(CartItem $item): ?string
    {
        $product = $item->product;
        if ($product->status !== 'active') return '商品已下架';
        if (! $product->hasEffectiveCategory()) return '商品分類目前無法購買';
        if ($item->product_variant_id !== null) {
            if ($item->productVariant === null || (int) $item->productVariant->product_id !== (int) $product->id) return '商品規格不存在';
            if ($item->productVariant->status !== 'active') return '商品規格已停用';
        } elseif ($product->stock === null) {
            return '請重新選擇商品規格';
        }
        $stock = $item->product_variant_id !== null ? $item->productVariant->stock : $product->stock;
        return (int) $stock < $item->quantity ? '商品庫存不足' : null;
    }
}
