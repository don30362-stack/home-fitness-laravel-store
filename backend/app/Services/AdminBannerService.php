<?php

namespace App\Services;

use App\Models\Banner;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class AdminBannerService
{
    public function __construct(private readonly BannerImageStorage $files) {}

    public function create(array $data): Banner
    {
        $path = $this->files->store($data['image']);
        unset($data['image']);
        try {
            return DB::transaction(function () use ($data, $path) {
                DB::afterRollBack(fn () => $this->files->cleanup(null, $path, 'create_rollback'));
                $this->validatePair($data['button_text'] ?? null, $data['link_url'] ?? null);
                $banner = new Banner($data + ['image_path' => $path, 'status' => 'active', 'sort_order' => 0]);
                if ($banner->saveOrFail() !== true || ! $banner->exists) {
                    throw new RuntimeException('無法建立輪播資料。');
                }
                return $banner;
            });
        } catch (Throwable $exception) {
            $this->files->cleanup(null, $path, 'create_failure');
            throw $exception;
        }
    }

    public function update(int $id, array $data): Banner
    {
        $image = $data['image'] ?? null;
        unset($data['image']);
        $path = $image instanceof UploadedFile ? $this->files->store($image) : null;
        try {
            return DB::transaction(function () use ($id, $data, $path) {
                if ($path !== null) {
                    DB::afterRollBack(fn () => $this->files->cleanup($id, $path, 'replace_rollback'));
                }
                $banner = Banner::query()->lockForUpdate()->findOrFail($id);
                $old = $banner->image_path;
                $banner->fill($data);
                $this->validatePair($banner->button_text, $banner->link_url);
                if ($path !== null) {
                    $banner->image_path = $path;
                }
                if ($banner->isDirty() && $banner->saveOrFail() !== true) {
                    throw new RuntimeException('無法更新輪播資料。');
                }
                if ($path !== null) {
                    DB::afterCommit(fn () => $this->files->cleanup($id, $old, 'replace_committed'));
                }
                return $banner;
            });
        } catch (Throwable $exception) {
            if ($path !== null) {
                $this->files->cleanup($id, $path, 'replace_failure');
            }
            throw $exception;
        }
    }

    public function changeStatus(int $id, string $status): Banner
    {
        return DB::transaction(function () use ($id, $status) {
            $banner = Banner::query()->lockForUpdate()->findOrFail($id);
            if ($banner->status !== $status) {
                $banner->status = $status;
                if ($banner->saveOrFail() !== true) {
                    throw new RuntimeException('無法更新輪播狀態。');
                }
            }
            return $banner;
        });
    }

    public function reorder(array $ids): void
    {
        DB::transaction(function () use ($ids) {
            $banners = Banner::query()->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $submitted = array_map('intval', $ids);
            $sorted = $submitted;
            sort($sorted);
            if ($sorted !== $banners->keys()->all()) {
                throw ValidationException::withMessages(['ids' => '輪播內容已變更，請重新載入後再調整順序。']);
            }
            foreach ($submitted as $position => $id) {
                $banner = $banners[$id];
                $banner->sort_order = $position;
                if ($banner->isDirty() && $banner->saveOrFail() !== true) {
                    throw new RuntimeException('無法更新輪播順序。');
                }
            }
        });
    }

    public function delete(int $id): void
    {
        DB::transaction(function () use ($id) {
            $banner = Banner::query()->lockForUpdate()->findOrFail($id);
            $path = $banner->image_path;
            if (! $banner->delete()) {
                throw new RuntimeException('無法刪除輪播資料。');
            }
            DB::afterCommit(fn () => $this->files->cleanup($id, $path, 'delete_committed'));
        });
    }

    private function validatePair(?string $text, ?string $link): void
    {
        if (($text === null) !== ($link === null)) {
            throw ValidationException::withMessages(['button_text' => '按鈕文字與站內連結必須同時填寫或同時清除。']);
        }
    }
}
