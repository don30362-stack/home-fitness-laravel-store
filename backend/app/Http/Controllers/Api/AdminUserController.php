<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AdminUserIndexRequest;
use App\Http\Requests\AdminUserShowRequest;
use App\Http\Requests\UpdateAdminUserStatusRequest;
use App\Http\Resources\AdminUserDetailResource;
use App\Http\Resources\AdminUserResource;
use App\Models\User;
use App\Services\AdminUserService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AdminUserController extends Controller
{
    public function index(AdminUserIndexRequest $request): AnonymousResourceCollection
    {
        $data = $request->validated();
        $query = User::query();
        if (isset($data['search']) && $data['search'] !== '') {
            $search = '%'.$data['search'].'%';
            $query->where(fn ($q) => $q->where('name', 'like', $search)->orWhere('email', 'like', $search));
        }
        if (isset($data['status'])) {
            $query->where('status', $data['status']);
        }
        return AdminUserResource::collection($query->orderByDesc('created_at')->orderByDesc('id')->paginate(10)->withQueryString());
    }

    public function show(AdminUserShowRequest $request, string $id): AdminUserDetailResource
    {
        $user = User::query()->findOrFail($id);
        $orders = $user->orders()->orderByDesc('created_at')->orderByDesc('id')
            ->paginate(10, ['*'], 'order_page')->withQueryString();
        return new AdminUserDetailResource(['user' => $user, 'orders' => $orders]);
    }

    public function updateStatus(UpdateAdminUserStatusRequest $request, string $id, AdminUserService $service): AdminUserResource
    {
        return (new AdminUserResource($service->updateStatus((int) $id, $request->validated('status'))))
            ->additional(['message' => '會員狀態更新成功。']);
    }
}
