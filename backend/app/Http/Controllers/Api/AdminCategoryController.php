<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAdminCategoryRequest;
use App\Http\Requests\UpdateAdminCategoryRequest;
use App\Http\Requests\UpdateAdminCategoryStatusRequest;
use App\Http\Resources\AdminCategoryMutationResource;
use App\Http\Resources\AdminCategoryResource;
use App\Models\Category;
use App\Services\AdminCategoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AdminCategoryController extends Controller
{
    public function store(StoreAdminCategoryRequest $request, AdminCategoryService $service): JsonResponse
    {
        $category = $service->loadManagementData($service->create($request->validated()));

        return (new AdminCategoryMutationResource($category))
            ->additional(['message' => '分類建立成功。'])->response()->setStatusCode(201);
    }

    public function update(UpdateAdminCategoryRequest $request, int $id, AdminCategoryService $service): AdminCategoryMutationResource
    {
        $category = $service->loadManagementData($service->update($id, $request->validated()));

        return (new AdminCategoryMutationResource($category))->additional(['message' => '分類更新成功。']);
    }

    public function status(UpdateAdminCategoryStatusRequest $request, int $id, AdminCategoryService $service): AdminCategoryMutationResource
    {
        $category = $service->loadManagementData($service->changeStatus($id, $request->validated('status')));
        return (new AdminCategoryMutationResource($category))->additional(['message' => '分類狀態更新成功。']);
    }

    public function index(): AnonymousResourceCollection
    {
        $categories = Category::query()
            ->whereNull('parent_id')
            ->withCount('children')
            ->with(['children' => fn ($query) => $query
                ->withCount(['products as product_count'])
                ->orderBy('sort_order')->orderBy('id')])
            ->orderBy('sort_order')->orderBy('id')
            ->get();

        return AdminCategoryResource::collection($categories);
    }
}
