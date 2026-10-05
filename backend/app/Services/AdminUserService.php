<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdminUserService
{
    public function updateStatus(int $id, string $status): User
    {
        return DB::transaction(function () use ($id, $status) {
            $user = User::query()->lockForUpdate()->findOrFail($id);
            if (! in_array($status, ['active', 'disabled'], true)
                || ! in_array($user->status, ['active', 'disabled', 'inactive'], true)
                || ($user->status === 'inactive' && $status !== 'active')) {
                throw ValidationException::withMessages(['status' => '此會員狀態不可進行指定變更；舊停用狀態只能恢復啟用。']);
            }
            // Status is intentionally excluded from profile mass assignment.
            $user->status = $status;
            if ($user->isDirty('status')) {
                $user->saveOrFail();
            }
            return $user;
        });
    }
}
