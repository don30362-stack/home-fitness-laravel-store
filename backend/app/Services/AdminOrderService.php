<?php

namespace App\Services;

use App\Models\Order;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class AdminOrderService
{
    public function updateStatus(int $id, string $status): Order
    {
        return $this->mutate($id, function (Order $order) use ($status): void {
            if ($order->order_status === 'cancelled' || $status === 'cancelled') {
                throw ValidationException::withMessages(['order_status' => '已取消訂單不可更新狀態；取消請使用專用取消操作。']);
            }
            if ($order->order_status === $status) {
                return;
            }
            if ($order->order_status === 'pending' && $status === 'processing') {
                $order->order_status = $status;
                return;
            }
            if ($order->order_status === 'shipped' && $status === 'completed') {
                if ($order->payment_status !== 'paid') {
                    throw ValidationException::withMessages(['order_status' => '訂單尚未付款，無法標記為完成。']);
                }
                $order->order_status = $status;
                return;
            }
            throw ValidationException::withMessages(['order_status' => $status === 'shipped'
                ? '出貨請使用物流出貨操作。' : '此訂單目前不允許此狀態轉移，不能倒退或跳級。']);
        });
    }

    public function updatePaymentStatus(int $id, string $status): Order
    {
        return $this->mutate($id, function (Order $order) use ($status): void {
            if ($order->order_status === 'cancelled') {
                throw ValidationException::withMessages(['payment_status' => '已取消訂單不可更新付款狀態。']);
            }
            if ($order->payment_status === $status) {
                return;
            }
            if ($order->payment_status !== 'unpaid' || $status !== 'paid') {
                throw ValidationException::withMessages(['payment_status' => '付款狀態只允許由未付款更新為已付款。']);
            }
            $order->payment_status = $status;
        });
    }

    public function updateShipment(int $id, array $data): Order
    {
        return $this->mutate($id, function (Order $order) use ($data): void {
            if (! in_array($order->order_status, ['processing', 'shipped'], true)) {
                throw ValidationException::withMessages(['logistics_company' => '此訂單目前不可更新物流資料，僅處理中或已出貨訂單可操作。']);
            }
            $order->logistics_company = $data['logistics_company'];
            $order->tracking_number = $data['tracking_number'];
            $order->order_status = 'shipped';
        });
    }

    private function mutate(int $id, Closure $change): Order
    {
        return DB::transaction(function () use ($id, $change): Order {
            // The shared Order lock is the serialization boundary; never inspect a pre-lock model.
            $order = Order::query()->lockForUpdate()->findOrFail($id);
            $change($order);
            if ($order->isDirty() && ! $order->saveOrFail()) {
                throw new RuntimeException('無法更新訂單資料。');
            }

            return $order->load(['user:id,name,email,status', 'items' => fn ($q) => $q->orderBy('id')]);
        });
    }
}
