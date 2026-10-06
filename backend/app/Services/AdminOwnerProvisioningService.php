<?php

namespace App\Services;

use App\Models\Admin;
use App\Models\Permission;
use Illuminate\Support\Facades\DB;
use LogicException;

class AdminOwnerProvisioningService
{
    public function grant(Admin $admin): void
    {
        DB::transaction(function () use ($admin): void {
            // Additive local/testing provisioning only; no status or profile changes.
            $target = Admin::query()->lockForUpdate()->findOrFail($admin->id);
            $ids = Permission::query()->whereIn('code', array_keys(Permission::CATALOG))->pluck('id');
            if ($ids->count() !== count(Permission::CATALOG)) {
                throw new LogicException('Permission catalog 不完整，請先執行 PermissionSeeder / migrate --seed。');
            }

            $target->permissions()->syncWithoutDetaching($ids->all());
        });
    }
}
