<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

// 小型可測的public disk邊界，不將任意舊DB路徑視為可刪檔案。
class ProductImageStorage
{
    public function store(int $productId, UploadedFile $file): string
    {
        $extension = $file->guessExtension(); // 根據真實MIME，不用client原始檔名。
        if (! in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            throw new RuntimeException('無法識別圖片格式。');
        }
        $disk = Storage::disk('public');
        $directory = 'products/'.$productId;
        if (! $disk->makeDirectory($directory)) {
            throw new RuntimeException('圖片目錄建立失敗。');
        }
        $root = realpath($disk->path(''));
        $parent = realpath($disk->path($directory));
        if ($root === false || $parent === false || ! $this->insideRoot($parent, $root)) {
            throw new RuntimeException('Unsafe storage boundary.');
        }
        $name = Str::uuid().'.'.($extension === 'jpeg' ? 'jpg' : $extension);
        // UUID加exists檢查，不使用client名稱覆蓋既有檔案。
        for ($attempt = 0; $disk->exists($directory.'/'.$name); $attempt++) {
            if ($attempt >= 9) {
                throw new RuntimeException('無法建立唯一圖片檔名。');
            }
            $name = Str::uuid().'.'.($extension === 'jpeg' ? 'jpg' : $extension);
        }
        try {
            $path = $disk->putFileAs($directory, $file, $name);
            if ($path === false) {
                throw new RuntimeException('圖片儲存失敗。');
            }
        } catch (Throwable $exception) {
            $this->cleanup($productId, null, $directory.'/'.$name, 'upload_storage_failure');
            throw $exception;
        }

        return $path;
    }

    public function cleanup(int $productId, ?int $imageId, string $path, string $reason): void
    {
        $context = ['product_id' => $productId, 'image_id' => $imageId, 'reason' => $reason];
        // 僅Stage3明確管理的product目錄/UUID檔名；Seeder舊扁平路徑不推定歸屬。
        if (! preg_match('#^products/'.$productId.'/[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}\.(jpg|png|webp)$#D', $path)) {
            Log::notice('Product image cleanup skipped: unmanaged path.', $context + ['path_hash' => hash('sha256', $path)]);

            return;
        }
        $failure = 'storage_exception';
        try {
            $disk = Storage::disk('public');
            // 防止local disk內的symlink/junction將安全相對路徑導向root以外。
            $root = realpath($disk->path(''));
            $target = $disk->path($path);
            $parent = realpath(dirname($target));
            if ($root === false || ($parent !== false && ! $this->insideRoot($parent, $root)) || is_link($target)) {
                $failure = 'unsafe_boundary';
                throw new RuntimeException('Unsafe storage boundary.');
            }
            if ($disk->exists($path) && ! $this->deleteFile($path)) {
                $failure = 'delete_returned_false';
                throw new RuntimeException('Storage delete returned false.');
            }
        } catch (Throwable $exception) {
            // logical delete已commit；不拋例外假装DB仍存在，也不吞掉upload原始例外。
            Log::error('Product image physical cleanup failed.', $context + ['path' => $path, 'error' => $exception::class, 'failure_kind' => $failure]);
        }
    }

    protected function deleteFile(string $path): bool
    {
        return Storage::disk('public')->delete($path);
    }

    private function insideRoot(string $path, string $root): bool
    {
        $path = rtrim(str_replace('\\', '/', $path), '/');
        $root = rtrim(str_replace('\\', '/', $root), '/');
        if (PHP_OS_FAMILY === 'Windows') {
            $path = strtolower($path);
            $root = strtolower($root);
        }

        return str_starts_with($path.'/', $root.'/');
    }
}
