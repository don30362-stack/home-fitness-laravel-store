<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAdminBannerRequest;
use App\Http\Requests\UpdateAdminBannerRequest;
use App\Http\Requests\UpdateAdminBannerStatusRequest;
use App\Http\Requests\ReorderAdminBannersRequest;
use App\Http\Resources\AdminBannerResource;
use App\Models\Banner;
use App\Services\AdminBannerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AdminBannerController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return AdminBannerResource::collection(Banner::query()->orderBy('sort_order')->orderBy('id')->get());
    }

    public function store(StoreAdminBannerRequest $request, AdminBannerService $service): JsonResponse
    {
        return (new AdminBannerResource($service->create($request->validated())))
            ->additional(['message' => '輪播新增成功。'])->response()->setStatusCode(201);
    }

    public function update(UpdateAdminBannerRequest $request, int $id, AdminBannerService $service): AdminBannerResource
    {
        return (new AdminBannerResource($service->update($id, $request->validated())))
            ->additional(['message' => '輪播更新成功。']);
    }

    public function status(UpdateAdminBannerStatusRequest $request, int $id, AdminBannerService $service): AdminBannerResource
    {
        return (new AdminBannerResource($service->changeStatus($id, $request->validated('status'))))
            ->additional(['message' => '輪播狀態更新成功。']);
    }

    public function order(ReorderAdminBannersRequest $request, AdminBannerService $service): JsonResponse
    {
        $service->reorder($request->validated('ids'));
        return response()->json(['message' => '輪播順序更新成功。']);
    }

    public function destroy(int $id, AdminBannerService $service): JsonResponse
    {
        $service->delete($id);
        return response()->json(['message' => '輪播已刪除。']);
    }
}
