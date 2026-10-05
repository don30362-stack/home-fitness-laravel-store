<?php

namespace App\Services;

use App\Models\Category;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdminCategoryService
{
    public function create(array $data): Category
    {
        return DB::transaction(function () use ($data) {
            $parentId = isset($data['parent_id']) ? (int) $data['parent_id'] : null;
            if ($parentId !== null) {
                $this->validateParent($parentId, true);
            }
            $this->validateName($data['name'], $parentId);

            return Category::create([
                'name' => $data['name'],
                'parent_id' => $parentId,
                'sort_order' => $data['sort_order'] ?? 0,
                'status' => $data['status'] ?? 'active',
            ]);
        });
    }

    public function update(int $id, array $data): Category
    {
        return DB::transaction(function () use ($id, $data) {
            $category = Category::query()->lockForUpdate()->findOrFail($id);
            $currentParent = $category->parent_id === null ? null : (int) $category->parent_id;
            $parentId = array_key_exists('parent_id', $data)
                ? ($data['parent_id'] === null ? null : (int) $data['parent_id'])
                : $currentParent;

            if (($currentParent === null) !== ($parentId === null)) {
                $this->reject('parent_id', '主分類與子分類的角色建立後不可互相轉換。');
            }
            if ($parentId === $id) {
                $this->reject('parent_id', '分類不可將自己設為上層分類。');
            }
            if ($parentId !== null) {
                // 同一 parent（含明確提交）不是搬移，允許保留 inactive root。
                $this->validateParent($parentId, $parentId !== $currentParent);
            }
            $name = $data['name'] ?? $category->name;
            $this->validateName($name, $parentId, $id);
            $category->fill(array_intersect_key($data, array_flip(['name', 'sort_order'])));
            $category->parent_id = $parentId;
            $category->save();

            return $category;
        });
    }

    public function changeStatus(int $id, string $status): Category
    {
        return DB::transaction(function () use ($id, $status) {
            $category = Category::query()->lockForUpdate()->findOrFail($id);
            if ($category->parent_id !== null) {
                $parent = Category::find($category->parent_id);
                if (! $parent || $parent->parent_id !== null) {
                    $this->reject('status', '異常分類階層不可變更狀態。');
                }
            }
            $category->status = $status;
            $category->save();
            return $category;
        });
    }

    public function loadManagementData(Category $category): Category
    {
        return $category->loadCount(['children', 'products as product_count'])
            ->load(['children' => fn ($query) => $query->withCount(['products as product_count'])
                ->orderBy('sort_order')->orderBy('id')]);
    }

    private function validateParent(int $id, bool $requireActive): void
    {
        $parent = Category::query()->find($id);
        if (! $parent || $parent->parent_id !== null || ($requireActive && $parent->status !== 'active')) {
            $this->reject('parent_id', '請選擇存在且啟用的主分類；原本停用的主分類只能原地保留。');
        }
    }

    private function validateName(string $name, ?int $parentId, ?int $exceptId = null): void
    {
        $query = Category::query()->where('name', $name);
        $parentId === null ? $query->whereNull('parent_id') : $query->where('parent_id', $parentId);
        if ($exceptId !== null) {
            $query->where('id', '!=', $exceptId);
        }
        if ($query->exists()) {
            $this->reject('name', '同一層級／上層分類下已有相同名稱的分類。');
        }
    }

    private function reject(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
