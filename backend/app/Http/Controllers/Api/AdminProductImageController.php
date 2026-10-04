<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAdminProductImageRequest;
use App\Http\Requests\UpdateAdminProductImageRequest;
use App\Http\Resources\ProductImageResource;
use App\Services\AdminProductImageService;
use Illuminate\Http\JsonResponse;

class AdminProductImageController extends Controller
{
    public function __construct(private readonly AdminProductImageService $images) {}

    public function store(StoreAdminProductImageRequest $request, string $productId): JsonResponse
    {
        $image = $this->images->upload((int) $productId, $request->file('image'), $request->validated());

        return (new ProductImageResource($image))->additional(['message' => '圖片上傳成功'])->response()->setStatusCode(201);
    }

    public function update(UpdateAdminProductImageRequest $request, string $imageId): JsonResponse
    {
        $image = $this->images->update((int) $imageId, $request->validated());

        return (new ProductImageResource($image))->additional(['message' => '圖片資料更新成功'])->response();
    }

    public function destroy(string $imageId): JsonResponse
    {
        $this->images->delete((int) $imageId);

        return response()->json(['message' => '圖片已刪除']);
    }
}
