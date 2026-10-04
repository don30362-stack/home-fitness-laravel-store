<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAdminProductRequest;
use App\Http\Requests\UpdateAdminProductRequest;
use App\Http\Requests\UpdateAdminProductStatusRequest;
use App\Http\Resources\AdminProductDetailResource;
use App\Http\Resources\AdminProductListResource;
use App\Models\Product;
use App\Services\AdminProductService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminProductController extends Controller
{
    public function __construct(private readonly AdminProductService $products) {}

    public function store(StoreAdminProductRequest $request): JsonResponse
    {
        $product = $this->products->create($request->validated());

        return $this->show((string) $product->id)->additional(['message' => '商品建立成功'])->response()->setStatusCode(201);
    }

    public function update(UpdateAdminProductRequest $request, string $id): JsonResponse
    {
        $product = $this->products->update((int) $id, $request->validated());

        return $this->show((string) $product->id)->additional(['message' => '商品更新成功'])->response();
    }

    public function updateStatus(UpdateAdminProductStatusRequest $request, string $id): JsonResponse
    {
        $product = $this->products->changeStatus((int) $id, $request->validated('status'));

        return $this->show((string) $product->id)->additional(['message' => '商品狀態更新成功'])->response();
    }

    public function destroy(string $id): JsonResponse
    {
        $this->products->delete((int) $id);

        return response()->json(['message' => '商品已刪除']);
    }

    public function index(Request $request)
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'category_id' => ['nullable', 'integer', 'min:1', Rule::exists('categories', 'id')->whereNotNull('parent_id')],
            'status' => ['nullable', Rule::in(['active', 'inactive', 'disabled'])],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $products = Product::query()->with('category')->withExists('variants');
        if (isset($validated['search']) && trim($validated['search']) !== '') {
            $search = trim($validated['search']);
            $products->where(function ($query) use ($search) {
                $query->where('name', 'like', '%'.$search.'%')
                    ->orWhere('product_code', 'like', '%'.$search.'%');
            });
        }
        if (isset($validated['category_id'])) {
            $products->where('category_id', $validated['category_id']);
        }
        if (isset($validated['status'])) {
            $products->where('status', $validated['status']);
        }

        return AdminProductListResource::collection(
            $products->orderByDesc('created_at')->orderByDesc('id')->paginate(10)->withQueryString()
        );
    }

    public function show(string $id): AdminProductDetailResource
    {
        $product = Product::query()->withExists('variants')->with([
            'category.parent',
            'images' => fn ($query) => $query->orderBy('sort_order')->orderBy('id'),
            'specifications' => fn ($query) => $query->orderBy('sort_order')->orderBy('id'),
            'variants' => fn ($query) => $query->orderBy('id'),
        ])->findOrFail($id);

        return new AdminProductDetailResource($product);
    }
}
