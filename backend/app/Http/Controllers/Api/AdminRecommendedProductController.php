<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAdminRecommendedProductRequest;
use App\Http\Requests\ReorderAdminRecommendedProductsRequest;
use App\Http\Resources\AdminRecommendedProductResource;
use App\Services\AdminRecommendedProductService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AdminRecommendedProductController extends Controller
{
    public function index(AdminRecommendedProductService $service): AnonymousResourceCollection
    {
        return AdminRecommendedProductResource::collection($service->managementQuery()->orderBy('sort_order')->orderBy('id')->get());
    }
    public function store(StoreAdminRecommendedProductRequest $request, AdminRecommendedProductService $service): JsonResponse
    {
        $relation = $service->create((int) $request->validated('product_id'));
        return (new AdminRecommendedProductResource($service->managementQuery()->findOrFail($relation->id)))
            ->additional(['message' => '推薦商品新增成功。'])->response()->setStatusCode(201);
    }
    public function order(ReorderAdminRecommendedProductsRequest $request, AdminRecommendedProductService $service): JsonResponse
    {
        $service->reorder($request->validated('ids'));
        return response()->json(['message' => '推薦商品順序更新成功。']);
    }
    public function destroy(int $id, AdminRecommendedProductService $service): JsonResponse
    {
        $service->delete($id);
        return response()->json(['message' => '推薦商品已移除。']);
    }
}
