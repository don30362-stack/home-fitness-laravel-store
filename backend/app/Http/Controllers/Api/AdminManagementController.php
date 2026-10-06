<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReplaceManagedAdminPermissionsRequest;
use App\Http\Requests\StoreManagedAdminRequest;
use App\Http\Requests\UpdateManagedAdminRequest;
use App\Http\Requests\UpdateManagedAdminStatusRequest;
use App\Http\Resources\ManagedAdminDetailResource;
use App\Http\Resources\ManagedAdminResource;
use App\Models\Admin;
use App\Models\Permission;
use App\Services\AdminManagementService;

class AdminManagementController extends Controller
{
    public function index()
    {
        return ManagedAdminResource::collection(Admin::query()->orderBy('id')->get());
    }

    public function catalog()
    {
        return response()->json(['data' => Permission::query()->whereIn('code', array_keys(Permission::CATALOG))->orderBy('code')->get(['id', 'code', 'name'])]);
    }

    public function show(string $id)
    {
        return $this->detail(Admin::query()->findOrFail($id));
    }

    public function store(StoreManagedAdminRequest $request, AdminManagementService $service)
    {
        return $this->detail($service->create($request->validated()))->additional(['message' => '管理員建立成功。'])->response()->setStatusCode(201);
    }

    public function update(UpdateManagedAdminRequest $request, string $id, AdminManagementService $service)
    {
        return $this->detail($service->update((int) $id, $request->validated()))->additional(['message' => '管理員基本資料已儲存。']);
    }

    public function status(UpdateManagedAdminStatusRequest $request, string $id, AdminManagementService $service)
    {
        return $this->detail($service->status($request->user('admin')->id, (int) $id, $request->validated('status')))->additional(['message' => '管理員狀態已更新。']);
    }

    public function permissions(ReplaceManagedAdminPermissionsRequest $request, string $id, AdminManagementService $service)
    {
        return $this->detail($service->permissions($request->user('admin')->id, (int) $id, $request->validated('permission_ids')))->additional(['message' => '管理員權限已更新。']);
    }

    private function detail(Admin $admin): ManagedAdminDetailResource
    {
        return new ManagedAdminDetailResource($admin->load('permissions'));
    }
}
