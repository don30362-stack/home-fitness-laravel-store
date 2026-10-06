<?php

namespace App\Console\Commands;

use App\Models\Admin;
use App\Services\AdminOwnerProvisioningService;
use Illuminate\Console\Command;
use LogicException;

class ProvisionAdminOwner extends Command
{
    protected $signature = 'admin:provision-owner {email : Existing Admin email}';

    protected $description = 'Grant the owner catalog to an existing local/testing Admin';

    public function handle(AdminOwnerProvisioningService $owners): int
    {
        if (! $this->laravel->environment('local', 'testing')) {
            $this->error('此指令僅允許在 local/testing 環境執行。');

            return self::FAILURE;
        }

        $admin = Admin::query()->where('email', trim((string) $this->argument('email')))->first();
        if (! $admin) {
            $this->error('找不到既有管理員，未建立或修改帳號。');

            return self::FAILURE;
        }

        try {
            $owners->grant($admin);
        } catch (LogicException $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }

        $this->info('既有管理員已補齊七項 owner permissions。');
        if ($admin->status !== 'active') {
            $this->warn('帳號仍為 '.$admin->status.'，目前不是可登入的 active owner。');
        }

        return self::SUCCESS;
    }
}
