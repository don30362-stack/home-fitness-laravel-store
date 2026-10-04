<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class AdminProductService
{
    private const BASIC_FIELDS = ['category_id', 'name', 'price', 'short_description', 'description', 'low_stock_threshold'];

    public function __construct(private readonly ProductCodeGenerator $codes) {}

    public function create(array $data): Product
    {
        // 常態碰撞由generator的exists檢查處理；unique只處理極少的併發競爭。
        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                return DB::transaction(function () use ($data) {
                    $this->validateCategory((int) $data['category_id']);
                    $variants = $data['variants'] ?? [];
                    $this->validateAxis($variants);
                    if ($variants === []) {
                        if (! isset($data['stock'])) {
                            $this->reject('stock', '無購買規格商品必須提供初始庫存。');
                        }
                    } elseif (isset($data['stock'])) {
                        $this->reject('stock', '有購買規格商品的商品庫存必須為 null。');
                    }
                    $product = Product::query()->create(Arr::only($data, self::BASIC_FIELDS) + [
                        'product_code' => $this->codes->generate(),
                        'status' => $data['status'] ?? 'active',
                        'stock' => $variants === [] ? (int) $data['stock'] : null,
                    ]);
                    $this->syncSpecifications($product, $data);
                    foreach ($variants as $row) {
                        $product->variants()->create($row);
                    }

                    return $product;
                }, 3);
            } catch (UniqueConstraintViolationException $exception) {
                if (! str_contains($exception->getMessage(), 'product_code')) {
                    throw $exception;
                }
                // 整個失敗transaction已rollback，重新產碼並重做aggregate。
            }
        }

        $this->reject('product_code', '暫時無法建立唯一商品編號，請稍後再試。');
    }

    public function update(int $id, array $data): Product
    {
        return DB::transaction(function () use ($id, $data) {
            $product = Product::query()->lockForUpdate()->findOrFail($id);
            $existing = $product->variants()->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            if (array_key_exists('category_id', $data)) {
                $this->validateCategory((int) $data['category_id'], (int) $product->category_id);
            }
            $product->fill(Arr::only($data, self::BASIC_FIELDS));
            if (! $product->saveOrFail()) {
                throw new RuntimeException('無法更新商品。');
            }
            $this->syncSpecifications($product, $data);

            if (array_key_exists('variants', $data)) {
                $rows = $data['variants'];
                if ($existing->isEmpty() && $rows !== []) {
                    $this->reject('variants', '商品建立後不可由無規格轉為有規格。');
                }
                if ($existing->isNotEmpty() && $rows === []) {
                    $this->reject('variants', '有購買規格商品至少保留一個規格，不可轉換庫存模式。');
                }
                $this->validateAxis($rows);
                $kept = [];
                foreach ($rows as $index => $row) {
                    if (array_key_exists('id', $row)) {
                        $variant = $existing->get((int) $row['id']);
                        if (! $variant || in_array($variant->id, $kept, true)) {
                            $this->reject("variants.$index.id", '規格必須是此商品自己的既有規格，且不可重複。');
                        }
                        if (array_key_exists('stock', $row)) {
                            $this->reject("variants.$index.stock", '既有規格庫存請由庫存管理調整。');
                        }
                        if (($variant->option_name !== $row['option_name'] || $variant->option_value !== $row['option_value'])
                            && $this->isReferenced($variant)) {
                            $this->reject("variants.$index.option_value", '已被購物車或訂單使用的規格不可變更選項身分；請新增規格並將舊規格設為 inactive。');
                        }
                        $variant->fill(Arr::only($row, ['option_name', 'option_value', 'status']));
                        if (! $variant->saveOrFail()) {
                            throw new RuntimeException('無法更新商品規格。');
                        }
                        $kept[] = $variant->id;
                    } else {
                        $product->variants()->create(Arr::only($row, ['option_name', 'option_value', 'stock', 'status']));
                    }
                }
                foreach ($existing as $variant) {
                    if (! in_array($variant->id, $kept, true)) {
                        if ($this->isReferenced($variant)) {
                            $this->reject('variants', '已被會員購物車或歷史訂單使用的規格不可刪除，請改為 inactive。');
                        }
                        if (! $variant->delete()) {
                            throw new RuntimeException('無法刪除商品規格。');
                        }
                    }
                }
            }

            return $product;
        }, 3);
    }

    public function changeStatus(int $id, string $status): Product
    {
        return DB::transaction(function () use ($id, $status) {
            $product = Product::query()->lockForUpdate()->findOrFail($id);
            $product->status = $status;
            if (! $product->saveOrFail()) {
                throw new RuntimeException('無法更新商品狀態。');
            }

            return $product;
        }, 3);
    }

    public function delete(int $id): void
    {
        DB::transaction(function () use ($id) {
            $product = Product::query()->lockForUpdate()->findOrFail($id);
            // 與Checkout/Cancel維持product→variant順序；真實delete/cart鎖競爭留Step5。
            $product->variants()->orderBy('id')->lockForUpdate()->get();
            if ($product->orderItems()->exists()) {
                $this->reject('product', '商品已有歷史訂單，不能實體刪除；請改為下架或停用。');
            }
            if (! $product->delete()) {
                throw new RuntimeException('無法刪除商品。');
            }
            // 本步只處理DB CASCADE，實體圖片清理由Step3接入。
        }, 3);
    }

    private function syncSpecifications(Product $product, array $data): void
    {
        if (array_key_exists('specifications', $data)) {
            $product->specifications()->delete();
            $product->specifications()->createMany($data['specifications']);
        }
    }

    private function validateCategory(int $id, ?int $current = null): void
    {
        if ($id === $current) {
            return; // 允許保留目前停用分類，不推導C07連帶停售。
        }
        $category = Category::query()->with('parent')->find($id);
        if (! $category || $category->parent_id === null || $category->status !== 'active'
            || ! $category->parent || $category->parent->parent_id !== null || $category->parent->status !== 'active') {
            $this->reject('category_id', '請選擇啟用主分類下的啟用子分類。');
        }
    }

    private function validateAxis(array $rows): void
    {
        if (count(array_unique(array_column($rows, 'option_name'))) > 1) {
            $this->reject('variants', '同一商品只能有一個購買規格軸。');
        }
        $values = array_column($rows, 'option_value');
        if (count(array_unique($values)) !== count($values)) {
            $this->reject('variants', '同商品的購買規格值不可重複。');
        }
    }

    private function isReferenced(ProductVariant $variant): bool
    {
        return $variant->orderItems()->exists() || $variant->cartItems()->exists();
    }

    private function reject(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
