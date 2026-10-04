<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class AdminInventoryService
{
    // UNION projection: one row for each real stock owner; filtering/pagination remain in SQL.
    public function rows(): Builder
    {
        $columns = 'p.id as product_id, p.product_code, p.name as product_name, p.status as product_status, '
            .'p.created_at as product_created_at, p.low_stock_threshold, c.id as category_id, c.name as category_name';
        $plain = DB::table('products as p')->join('categories as c', 'c.id', '=', 'p.category_id')
            ->selectRaw($columns.", 'product' as stock_owner_type, p.id as stock_owner_id, p.stock, "
                .'NULL as variant_id, NULL as option_name, NULL as option_value, NULL as variant_status')
            ->whereNotNull('p.stock')->whereNotExists(fn ($query) => $query->selectRaw('1')
            ->from('product_variants as v')->whereColumn('v.product_id', 'p.id'));
        $variants = DB::table('product_variants as v')->join('products as p', 'p.id', '=', 'v.product_id')
            ->join('categories as c', 'c.id', '=', 'p.category_id')
            ->selectRaw($columns.", 'variant' as stock_owner_type, v.id as stock_owner_id, v.stock, "
                .'v.id as variant_id, v.option_name, v.option_value, v.status as variant_status')
            ->whereNull('p.stock');

        return DB::query()->fromSub($plain->unionAll($variants), 'inventory')
            ->select('inventory.*')->selectRaw("CASE WHEN stock = 0 THEN 'out_of_stock' "
                ."WHEN stock <= low_stock_threshold THEN 'low_stock' ELSE 'normal' END as inventory_status");
    }

    public function row(string $type, int $id): object
    {
        return $this->rows()->where('stock_owner_type', $type)->where('stock_owner_id', $id)->firstOrFail();
    }

    public function adjustProduct(int $id, int $adjustment): object
    {
        return DB::transaction(function () use ($id, $adjustment) {
            $product = Product::query()->lockForUpdate()->findOrFail($id);
            if ($product->variants()->exists()) {
                throw ValidationException::withMessages(['adjustment' => '此商品有購買規格，請使用規格庫存調整入口。']);
            }
            if ($product->stock === null) {
                throw ValidationException::withMessages(['adjustment' => '商品不是有效的無規格庫存位置。']);
            }
            $this->apply($product, $adjustment);

            return $this->row('product', $id);
        }, 3);
    }

    public function adjustVariant(int $id, int $adjustment): object
    {
        $parentId = ProductVariant::query()->findOrFail($id)->product_id;

        return DB::transaction(function () use ($id, $parentId, $adjustment) {
            // Match Checkout/Cancel: always acquire parent product before target variant.
            $product = Product::query()->lockForUpdate()->findOrFail($parentId);
            $variant = ProductVariant::query()->lockForUpdate()->findOrFail($id);
            if ((int) $variant->product_id !== (int) $product->id || $product->stock !== null) {
                throw ValidationException::withMessages(['adjustment' => '規格不是有效的商品庫存位置。']);
            }
            $this->apply($variant, $adjustment);

            return $this->row('variant', $id);
        }, 3);
    }

    private function apply(Product|ProductVariant $owner, int $adjustment): void
    {
        $stock = (int) $owner->stock + $adjustment;
        if ($adjustment === 0 || $stock < 0 || $stock > 4294967295) {
            throw ValidationException::withMessages(['adjustment' => '增減數量不可為 0，調整後庫存須介於 0 與 4294967295。']);
        }
        $owner->stock = $stock;
        if (! $owner->saveOrFail()) {
            throw new RuntimeException('無法更新庫存。');
        }
    }
}
