<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class RecommendedProductController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $products = Product::query()
            ->join('recommended_products', 'recommended_products.product_id', '=', 'products.id')
            ->select('products.*')
            ->sellable()
            ->with(['category', 'images'])
            ->orderBy('recommended_products.sort_order')
            ->orderBy('recommended_products.id')
            ->get();

        return ProductResource::collection($products);
    }
}
