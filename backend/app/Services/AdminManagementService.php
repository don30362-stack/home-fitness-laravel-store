<?php

namespace App\Services;

use App\Models\Admin;
use App\Models\Permission;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
use RuntimeException;

class AdminManagementService
{
    public function create(array $data): Admin
    {
        return $this->emailOperation(function () use ($data) {
            return DB::transaction(function () use ($data) {
                unset($data['password_confirmation']);
                $admin = new Admin($data + ['status' => 'active']);
                $this->save($admin);

                return $admin;
            });
        });
    }

    public function update(int $id, array $data): Admin
    {
        return $this->emailOperation(fn () => DB::transaction(function () use ($id, $data) {
            $admin = Admin::query()->lockForUpdate()->findOrFail($id);
            $admin->fill($data);
            if ($admin->isDirty()) {
                $this->save($admin);
            }

            return $admin;
        }));
    }

    public function status(int $actorId, int $id, string $status): Admin
    {
        return DB::transaction(function () use ($actorId, $id, $status) {
            [$target,$anchor] = $this->lockManagement($actorId, $id);
            if ($target->status === $status) {
                return $target;
            }
            $reduces = $target->status === 'active' && $status === 'disabled' && $target->permissions()->whereKey($anchor->id)->exists();
            $this->checkInvariant($reduces, 'status');
            $target->status = $status;
            $this->save($target);

            return $target;
        });
    }

    public function permissions(int $actorId, int $id, array $ids): Admin
    {
        return DB::transaction(function () use ($actorId, $id, $ids) {
            [$target,$anchor] = $this->lockManagement($actorId, $id);
            $catalog = Permission::query()->whereIn('code', array_keys(Permission::CATALOG))->pluck('id')->map(fn ($id) => (int) $id)->all();
            if (count($catalog) !== count(Permission::CATALOG)) {
                throw new LogicException('Permission catalog 不完整。');
            }
            $ids = array_map('intval', $ids);
            if (count($ids) !== count(array_unique($ids)) || array_diff($ids, $catalog)) {
                throw ValidationException::withMessages(['permission_ids' => '請選擇正式目錄中不重複的權限。']);
            }
            $reduces = $target->status === 'active' && $target->permissions()->whereKey($anchor->id)->exists() && ! in_array((int) $anchor->id, $ids, true);
            $this->checkInvariant($reduces, 'permission_ids');
            $unknown = $target->permissions()->whereNotIn('code', array_keys(Permission::CATALOG))->pluck('permissions.id')->all();
            $target->permissions()->sync(array_merge($unknown, $ids));

            return $target;
        });
    }

    private function lockManagement(int $actorId, int $id): array
    {
        // Common serialization boundary: Permission anchor -> Admin IDs ASC.
        $anchor = Permission::query()->where('code', 'admin_manage')->lockForUpdate()->first();
        if (! $anchor) {
            throw new LogicException('admin_manage Permission anchor 不存在。');
        }
        $rows = Admin::query()->whereIn('id', array_unique([$actorId, $id]))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $actor = $rows->get($actorId);
        if (! $actor || $actor->status !== 'active') {
            $this->denied('ADMIN_ACCOUNT_DISABLED', '此管理員帳號已停用，請聯絡管理員');
        }
        if (! $actor->permissions()->whereKey($anchor->id)->exists()) {
            $this->denied('ADMIN_PERMISSION_DENIED', '您沒有此功能的操作權限。');
        }
        $target = $rows->get($id);
        if (! $target) {
            throw (new ModelNotFoundException)->setModel(Admin::class, [$id]);
        }

        return [$target, $anchor];
    }

    private function checkInvariant(bool $reduces, string $field): void
    {
        $count = Admin::query()->where('status', 'active')->whereHas('permissions', fn ($q) => $q->where('code', 'admin_manage'))->count();
        if ($count - ($reduces ? 1 : 0) < 1) {
            throw ValidationException::withMessages([$field => '系統至少必須保留一位啟用且具有管理員管理權限的管理員。']);
        }
    }

    private function denied(string $code, string $message): never
    {
        throw new HttpResponseException(response()->json(compact('code', 'message'), 403));
    }

    private function save(Admin $admin): void
    {
        if ($admin->saveOrFail() !== true || ! $admin->exists) {
            throw new RuntimeException('無法儲存管理員資料。');
        }
    }

    private function emailOperation(callable $operation): Admin
    {
        try {
            return $operation();
        } catch (QueryException $e) {
            $info = $e->errorInfo;
            $message = $info[2] ?? '';
            $emailUnique = (($info[1] ?? null) === 1062 && str_contains($message, 'admins_email_unique')) || (($info[1] ?? null) === 19 && str_contains($message, 'UNIQUE constraint failed: admins.email'));
            if ($emailUnique) {
                throw ValidationException::withMessages(['email' => '此 Email 已被使用。']);
            }
            throw $e;
        }
    }
}
