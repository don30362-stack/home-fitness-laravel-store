<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AdjustAdminInventoryRequest;
use App\Http\Resources\AdminInventoryResource;
use App\Services\AdminInventoryService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminInventoryController extends Controller
{
    public function __construct(private readonly AdminInventoryService $inventory) {}

    public function index(Request $request)
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'category_id' => ['nullable', 'integer', 'min:1', Rule::exists('categories', 'id')->whereNotNull('parent_id')],
            'inventory_status' => ['nullable', Rule::in(['normal', 'low_stock', 'out_of_stock'])],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $query = $this->inventory->rows();
        if (isset($data['search']) && trim($data['search']) !== '') {
            $search = '%'.trim($data['search']).'%';
            $query->where(fn ($q) => $q->where('product_name', 'like', $search)->orWhere('product_code', 'like', $search));
        }
        if (isset($data['category_id'])) {
            $query->where('category_id', $data['category_id']);
        }
        if (isset($data['inventory_status'])) {
            match ($data['inventory_status']) {
                'out_of_stock' => $query->where('stock', 0),
                'low_stock' => $query->where('stock', '>', 0)->whereColumn('stock', '<=', 'low_stock_threshold'),
                'normal' => $query->whereColumn('stock', '>', 'low_stock_threshold'),
            };
        }

        return AdminInventoryResource::collection($query->orderByDesc('product_created_at')->orderByDesc('product_id')
            ->orderBy('stock_owner_type')->orderBy('stock_owner_id')->paginate(10)->withQueryString());
    }

    public function adjustProduct(AdjustAdminInventoryRequest $request, string $productId): AdminInventoryResource
    {
        return (new AdminInventoryResource($this->inventory->adjustProduct((int) $productId, (int) $request->validated('adjustment'))))
            ->additional(['message' => '庫存調整成功']);
    }

    public function adjustVariant(AdjustAdminInventoryRequest $request, string $variantId): AdminInventoryResource
    {
        return (new AdminInventoryResource($this->inventory->adjustVariant((int) $variantId, (int) $request->validated('adjustment'))))
            ->additional(['message' => '庫存調整成功']);
    }
}
