<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrderSummaryResource;
use App\Http\Resources\OrderResource;
use App\Services\OrderCancellationService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class OrderController extends Controller
{
    public function cancel(Request $request, string $id, OrderCancellationService $service): OrderResource
    {
        $order = $request->user()->orders()->findOrFail($id);

        return (new OrderResource($service->cancel((int) $order->id)))
            ->additional(['message' => '訂單已取消。']);
    }

    public function show(Request $request, string $id): OrderResource
    {
        $order = $request->user()->orders()->with('items')->findOrFail($id);

        return new OrderResource($order);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $orders = $request->user()->orders()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(10);

        return OrderSummaryResource::collection($orders);
    }
}
