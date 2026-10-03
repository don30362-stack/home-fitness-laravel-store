<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AdminProductDetailResource;
use App\Http\Resources\AdminProductListResource;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminProductController extends Controller
{
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
