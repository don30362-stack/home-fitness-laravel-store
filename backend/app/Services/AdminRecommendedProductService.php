<?php

namespace App\Services;

use App\Models\Product;
use App\Models\RecommendedProduct;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class AdminRecommendedProductService
{
    public function managementQuery(): Builder
    {
        return RecommendedProduct::query()->with(['product' => fn ($query) => $query
            ->with('category.parent')->withExists('variants')]);
    }

    public function create(int $productId): RecommendedProduct
    {
        try {
            return DB::transaction(function () use ($productId) {
                Product::query()->lockForUpdate()->findOrFail($productId);
                if (RecommendedProduct::query()->where('product_id', $productId)->exists()) {
                    $this->duplicate();
                }
                $relation = new RecommendedProduct(['product_id' => $productId]);
                if ($relation->saveOrFail() !== true || ! $relation->exists) {
                    throw new RuntimeException('無法建立推薦商品設定。');
                }
                return $relation->refresh(); // Includes DB sort_order default.
            });
        } catch (QueryException $exception) {
            $info = $exception->errorInfo;
            $message = $info[2] ?? '';
            $mysqlDuplicate = ($info[0] ?? null) === '23000' && (int) ($info[1] ?? 0) === 1062
                && preg_match('/for key [\x27`"](?:recommended_products\.)?recommended_products_product_id_unique[\x27`"]/i', $message);
            $sqliteDuplicate = ($info[0] ?? null) === '23000' && (int) ($info[1] ?? 0) === 19
                && str_ends_with($message, 'UNIQUE constraint failed: recommended_products.product_id');
            if ($mysqlDuplicate || $sqliteDuplicate) {
                $this->duplicate();
            }
            throw $exception;
        }
    }

    public function delete(int $id): void
    {
        DB::transaction(function () use ($id) {
            $relation = RecommendedProduct::query()->lockForUpdate()->findOrFail($id);
            if (! $relation->delete()) {
                throw new RuntimeException('無法移除推薦商品設定。');
            }
        });
    }

    public function reorder(array $ids): void
    {
        DB::transaction(function () use ($ids) {
            $relations = RecommendedProduct::query()->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $submitted = array_map('intval', $ids); $sorted = $submitted; sort($sorted);
            if ($sorted !== $relations->keys()->all()) {
                throw ValidationException::withMessages(['ids' => '推薦商品內容已變更，請重新載入後再調整順序。']);
            }
            foreach ($submitted as $position => $id) {
                $relation = $relations[$id]; $relation->sort_order = $position;
                if ($relation->isDirty() && $relation->saveOrFail() !== true) {
                    throw new RuntimeException('無法更新推薦商品順序。');
                }
            }
        });
    }

    private function duplicate(): never
    {
        throw ValidationException::withMessages(['product_id' => '此商品已是推薦商品。']);
    }
}
