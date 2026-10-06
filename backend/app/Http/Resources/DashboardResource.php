<?php

namespace App\Http\Resources;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DashboardResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $orders = $this->resource['orders'];
        if ($orders !== null) {
            $orders['recent_orders'] = $orders['recent_orders']->map(fn (Order $order) => [
                'id' => $order->id,
                'order_no' => $order->order_no,
                'created_at' => $order->created_at?->toISOString(),
                'total_amount' => $order->total_amount,
                'order_status' => $order->order_status,
                'payment_status' => $order->payment_status,
            ])->all();
        }

        return [
            'products' => $this->resource['products'],
            'members' => $this->resource['members'],
            'orders' => $orders,
        ];
    }
}
