<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class OrderCancellationService
{
    public function cancel(int $orderId): Order
    {
        return DB::transaction(function () use ($orderId): Order {
            // Do not use a status loaded before acquiring the order lock.
            $order = Order::query()->lockForUpdate()->findOrFail($orderId);

            if ($order->order_status === 'cancelled') {
                return $order->load('items');
            }

            if (! in_array($order->order_status, ['pending', 'processing'], true)) {
                throw ValidationException::withMessages([
                    'order' => '此訂單目前的狀態不允許取消。',
                ]);
            }

            // Match Checkout: ascending product / variant, product lock before variant.
            $items = $order->items()
                ->orderBy('product_id')
                ->orderBy('product_variant_id')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($items as $item) {
                $product = Product::query()->whereKey($item->product_id)->lockForUpdate()->first();

                if ($product === null) {
                    throw ValidationException::withMessages([
                        'order' => '訂單商品參照不存在，無法取消訂單。',
                    ]);
                }

                $stockOwner = $product;
                if ($item->product_variant_id !== null) {
                    $stockOwner = ProductVariant::query()
                        ->whereKey($item->product_variant_id)
                        ->where('product_id', $product->id)
                        ->lockForUpdate()
                        ->first();

                    if ($stockOwner === null) {
                        throw ValidationException::withMessages([
                            'order' => '訂單規格參照不存在，無法取消訂單。',
                        ]);
                    }
                }

                // Restore the original stock location even when it is inactive.
                $stockOwner->stock = (int) $stockOwner->stock + $item->quantity;
                if (! $stockOwner->saveOrFail()) {
                    throw new RuntimeException('無法恢復訂單庫存。');
                }
            }

            $order->order_status = 'cancelled';
            if (! $order->saveOrFail()) {
                throw new RuntimeException('無法更新訂單狀態。');
            }

            return $order->load('items');
        }, 3);
    }
}
