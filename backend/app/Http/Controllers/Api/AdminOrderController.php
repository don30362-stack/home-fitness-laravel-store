<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AdminOrderIndexRequest;
use App\Http\Requests\UpdateAdminOrderStatusRequest;
use App\Http\Requests\UpdateAdminOrderPaymentStatusRequest;
use App\Http\Requests\UpdateAdminOrderShipmentRequest;
use App\Http\Resources\AdminOrderResource;
use App\Http\Resources\AdminOrderSummaryResource;
use App\Models\Order;
use App\Services\AdminOrderService;
use App\Services\OrderCancellationService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AdminOrderController extends Controller
{
    public function cancel(string $id, OrderCancellationService $service): AdminOrderResource
    {
        $order = $service->cancel((int) $id);
        $order->loadMissing('user:id,name,email,status');

        return (new AdminOrderResource($order))->additional(['message' => '訂單已取消。']);
    }

    public function updateStatus(UpdateAdminOrderStatusRequest $request, string $id, AdminOrderService $service): AdminOrderResource
    {
        return (new AdminOrderResource($service->updateStatus((int) $id, $request->validated('order_status'))))
            ->additional(['message' => '訂單狀態更新成功。']);
    }

    public function updatePaymentStatus(UpdateAdminOrderPaymentStatusRequest $request, string $id, AdminOrderService $service): AdminOrderResource
    {
        return (new AdminOrderResource($service->updatePaymentStatus((int) $id, $request->validated('payment_status'))))
            ->additional(['message' => '付款狀態更新成功。']);
    }

    public function updateShipment(UpdateAdminOrderShipmentRequest $request, string $id, AdminOrderService $service): AdminOrderResource
    {
        return (new AdminOrderResource($service->updateShipment((int) $id, $request->validated())))
            ->additional(['message' => '物流資料更新成功。']);
    }

    public function index(AdminOrderIndexRequest $request): AnonymousResourceCollection
    {
        $data = $request->validated();
        $query = Order::query()->with('user:id,name,email');

        if (isset($data['search']) && $data['search'] !== '') {
            $search = '%'.$data['search'].'%';
            $query->where(fn ($q) => $q->where('order_no', 'like', $search)
                ->orWhereHas('user', fn ($user) => $user->where(fn ($identity) =>
                    $identity->where('name', 'like', $search)->orWhere('email', 'like', $search))));
        }
        foreach (['order_status', 'payment_status'] as $field) {
            if (isset($data[$field])) {
                $query->where($field, $data[$field]);
            }
        }
        if (isset($data['date_from'])) {
            $query->where('created_at', '>=', CarbonImmutable::createFromFormat('!Y-m-d', $data['date_from'], 'Asia/Taipei')->utc());
        }
        if (isset($data['date_to'])) {
            $query->where('created_at', '<', CarbonImmutable::createFromFormat('!Y-m-d', $data['date_to'], 'Asia/Taipei')->addDay()->utc());
        }

        return AdminOrderSummaryResource::collection(
            $query->orderByDesc('created_at')->orderByDesc('id')->paginate(10)->withQueryString()
        );
    }

    public function show(string $id): AdminOrderResource
    {
        return new AdminOrderResource(Order::query()->with(['user:id,name,email,status', 'items' => fn ($q) => $q->orderBy('id')])->findOrFail($id));
    }
}
