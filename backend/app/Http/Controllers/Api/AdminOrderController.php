<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AdminOrderIndexRequest;
use App\Http\Resources\AdminOrderResource;
use App\Http\Resources\AdminOrderSummaryResource;
use App\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AdminOrderController extends Controller
{
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
