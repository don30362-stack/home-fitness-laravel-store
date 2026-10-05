<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Category;
use App\Http\Resources\ProductResource;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'category_id' => ['nullable', 'integer', 'min:1'],
            'parent_category_id' => ['nullable', 'integer', 'min:1'],
            'min_price' => ['nullable', 'numeric', 'min:0'],
            'max_price' => ['nullable', 'numeric', 'min:0'],
            'sort' => ['nullable', 'in:price_asc,price_desc'],
            'page' => ['nullable', 'integer', 'min:1']
        ]);

        if (
            $request->filled('min_price') &&
            $request->filled('max_price') &&
            $request->input('min_price') > $request->input('max_price')
        ) {
            return response()->json([
                'message' => '最高價格不得低於最低價格'
            ], 422);
        }

        $products = Product::query()
            ->with(['category', 'images'])
            ->sellable();

        if ($request->filled('category_id')) {
            $category = Category::query()
                ->whereKey($request->integer('category_id'))
                ->whereNotNull('parent_id')
                ->where('status', 'active')
                ->whereHas('parent', fn ($parent) => $parent->whereNull('parent_id')->where('status', 'active'))
                ->first();

            if (!$category) {
                return response()->json([
                    'message' => '商品分類不存在',
                ], 404);
            }

            $products->where('category_id', $category->id);
        }

        if ($request->filled('parent_category_id')) {
            $parentCategory = Category::query()
                ->whereKey($request->integer('parent_category_id'))
                ->whereNull('parent_id')
                ->where('status', 'active')
                ->first();

            if (!$parentCategory) {
                return response()->json([
                    'message' => '商品分類不存在',
                ], 404);
            }

            $products->whereHas('category', function ($query) use ($parentCategory) {
                $query->where('parent_id', $parentCategory->id);
            });
        }

        if ($request->filled('search')) {
            $search = trim($request->input('search'));

            $products->where('name', 'like', '%' . $search . '%');
        }

        if ($request->filled('min_price')) {
            $products->where('price', '>=', $request->input('min_price'));
        }

        if ($request->filled('max_price')) {
            $products->where('price', '<=', $request->input('max_price'));
        }

        switch ($request->input('sort')) {
            case 'price_asc':
                $products->orderBy('price', 'asc');
                break;

            case 'price_desc':
                $products->orderBy('price', 'desc');
                break;

            default:
                $products->orderBy('id', 'asc');
                break;
        }

        return ProductResource::collection($products->paginate(8));
    }

    public function show($id)
    {
        $product = Product::with(['category', 'images', 'specifications', 'variants',])
            ->sellable()
            ->find($id);

        if (!$product) {
            return response()->json([
                'message' => '商品不存在'
            ], 404);
        }

        return new ProductResource($product);
    }

    public function related($id)
    {
        $product = Product::query()
            ->sellable()
            ->find($id);

        if (!$product) {
            return response()->json([
                'message' => '商品不存在'
            ], 404);
        }

        $relatedProducts = Product::query()
            ->with(['category', 'images'])
            ->sellable()
            ->where('category_id', $product->category_id)
            ->where('id', '!=', $product->id)
            ->limit(4)
            ->get();

        return ProductResource::collection($relatedProducts);
    }
}
