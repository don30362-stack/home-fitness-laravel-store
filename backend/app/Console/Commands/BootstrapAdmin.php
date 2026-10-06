<?php

namespace App\Console\Commands;

use App\Models\Admin;
use App\Services\AdminOwnerProvisioningService;
use Illuminate\Console\Command;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;

class BootstrapAdmin extends Command
{
    protected $signature = 'admin:bootstrap';

    protected $description = 'Create a local/testing Admin using interactive credentials';

    public function handle(AdminOwnerProvisioningService $owners): int
    {
        if (! $this->laravel->environment('local', 'testing')) {
            $this->error('此指令僅允許在 local/testing 環境執行。');

            return self::FAILURE;
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
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        if (Admin::query()->where('email', $data['email'])->exists()) {
            $this->error('此 Email 已存在，未建立或修改管理員。');

            return self::FAILURE;
        }

        try {
            DB::transaction(function () use ($data, $owners): void {
                $admin = new Admin([
                    'name' => $data['name'],
                    'email' => $data['email'],
                    'password' => $data['password'],
                    'status' => 'active',
                ]);
                if (! $admin->saveOrFail() || ! $admin->exists) {
                    throw new RuntimeException('無法建立管理員資料。');
                }
                $owners->grant($admin);
            });
        } catch (UniqueConstraintViolationException) {
            $this->error('此 Email 已存在，未建立或修改管理員。');

            return self::FAILURE;
        } catch (LogicException $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }

        $this->info('本機開發管理員已建立。');

        return self::SUCCESS;
    }
}
