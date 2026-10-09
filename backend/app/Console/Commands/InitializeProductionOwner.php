<?php

namespace App\Console\Commands;

use App\Models\Admin;
use App\Models\Permission;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use LogicException;
use RuntimeException;
use Throwable;

class InitializeProductionOwner extends Command
{
    protected $signature = 'admin:initialize-production-owner';

    protected $description = 'Interactively create the first production Admin with the seven owner permissions';

    public function handle(): int
    {
        if (! $this->laravel->environment('production') || ! $this->input->isInteractive()) {
            $this->error('此指令僅允許在 production 環境互動式執行。');

            return self::FAILURE;
        }

        try {
            if (Admin::query()->exists()) {
                throw new LogicException('已有管理員帳號，拒絕初始化或修改既有帳號。');
            }

            $data = [
                'name' => trim((string) $this->ask('管理員姓名')),
                'email' => trim((string) $this->ask('管理員 Email')),
                'password' => $this->secret('管理員密碼', false),
                'password_confirmation' => $this->secret('再次輸入密碼', false),
            ];
            $validator = Validator::make($data, [
                'name' => ['required', 'string', 'max:50'],
                'email' => ['required', 'string', 'email', 'max:255'],
                'password' => ['required', 'string', 'min:8', 'confirmed'],
            ]);
            if ($validator->fails()) {
                foreach ($validator->errors()->all() as $message) $this->error($message);

                return self::FAILURE;
            }

            DB::transaction(function () use ($data): void {
                // Existing catalog rows serialize concurrent initializers on InnoDB.
                // Check Admin again using a locking/current read after acquiring them.
                $permissions = Permission::query()->whereIn('code', array_keys(Permission::CATALOG))
                    ->orderBy('id')->lockForUpdate()->get();
                if ($permissions->count() !== count(Permission::CATALOG)) {
                    throw new LogicException('Permission catalog 不完整，拒絕初始化。');
                }
                if (Admin::query()->orderBy('id')->lockForUpdate()->first()) {
                    throw new LogicException('已有管理員帳號，拒絕初始化或修改既有帳號。');
                }

                $admin = new Admin([
                    'name' => $data['name'], 'email' => $data['email'],
                    'password' => $data['password'], 'status' => 'active',
                ]);
                if (! $admin->saveOrFail() || ! $admin->exists) {
                    throw new RuntimeException('無法建立管理員資料。');
                }
                $admin->permissions()->attach($permissions->modelKeys());
            });
        } catch (LogicException $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        } catch (Throwable) {
            // Do not print/log exception SQL bindings or interactive credentials.
            $this->error('無法安全完成初始化，未提交帳號或權限變更。');

            return self::FAILURE;
        }

        $this->info('正式初始管理員已建立並授予七項權限。');

        return self::SUCCESS;
    }
}
