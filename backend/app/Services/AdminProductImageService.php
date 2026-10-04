<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class AdminProductImageService
{
    public function __construct(private readonly ProductImageStorage $files) {}

    public function upload(int $productId, UploadedFile $file, array $metadata): ProductImage
    {
        Product::query()->findOrFail($productId);
        $path = $this->files->store($productId, $file);
        try {
            return DB::transaction(function () use ($productId, $path, $metadata) {
                $product = Product::query()->lockForUpdate()->findOrFail($productId);
                $images = $this->images($product);
                $image = $product->images()->create([
                    'image_path' => $path, 'image_type' => $metadata['image_type'] ?? 'gallery',
                    'sort_order' => $metadata['sort_order'] ?? 0, 'is_primary' => false,
                ]);
                if (! $image->exists) {
                    throw new RuntimeException('無法建立圖片資料。');
                }
                $images->push($image);
                $this->primary($product, $images, ! empty($metadata['is_primary']) ? $image->id : null);

                return $image->refresh();
            }, 3);
        } catch (Throwable $exception) {
            $this->files->cleanup($productId, null, $path, 'upload_compensation');
            throw $exception;
        }
    }

    public function update(int $id, array $metadata): ProductImage
    {
        $parentId = ProductImage::query()->findOrFail($id)->product_id;

        return DB::transaction(function () use ($parentId, $id, $metadata) {
            $product = Product::query()->lockForUpdate()->findOrFail($parentId);
            $images = $this->images($product);
            $image = $images->firstWhere('id', $id);
            abort_unless($image, 404);
            if (array_key_exists('is_primary', $metadata) && ! $metadata['is_primary'] && $image->is_primary) {
                throw ValidationException::withMessages(['is_primary' => '目前主圖不可直接取消，請先將另一張圖片設為主圖。']);
            }
            $image->fill(array_intersect_key($metadata, array_flip(['image_type', 'sort_order'])));
            if (! $image->saveOrFail()) {
                throw new RuntimeException('無法更新圖片資料。');
            }
            $this->primary($product, $images, ! empty($metadata['is_primary']) ? $image->id : null);

            return $image->refresh();
        }, 3);
    }

    public function delete(int $id): void
    {
        $parentId = ProductImage::query()->findOrFail($id)->product_id;
        DB::transaction(function () use ($parentId, $id) {
            $product = Product::query()->lockForUpdate()->findOrFail($parentId);
            $images = $this->images($product);
            $image = $images->firstWhere('id', $id);
            abort_unless($image, 404);
            if (! $image->delete()) {
                throw new RuntimeException('無法刪除圖片資料。');
            }
            $this->primary($product, $images->reject(fn ($row) => $row->id === $id));
            DB::afterCommit(fn () => $this->files->cleanup($product->id, $id, $image->image_path, 'image_delete'));
        }, 3);
    }

    private function images(Product $product): Collection
    {
        return $product->images()->orderBy('sort_order')->orderBy('id')->lockForUpdate()->get();
    }

    private function primary(Product $product, Collection $images, ?int $desired = null): void
    {
        if ($images->isEmpty()) {
            return;
        }
        $selected = $desired ?? $images->firstWhere('is_primary', true)?->id ?? $images->sortBy([['sort_order', 'asc'], ['id', 'asc']])->first()->id;
        $product->images()->where('is_primary', true)->where('id', '!=', $selected)->update(['is_primary' => false]);
        $product->images()->whereKey($selected)->update(['is_primary' => true]);
    }
}
