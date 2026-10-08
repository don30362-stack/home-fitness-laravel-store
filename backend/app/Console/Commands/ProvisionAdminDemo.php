<?php

namespace App\Console\Commands;

use App\Models\Admin;
use App\Services\AdminDemoService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class ProvisionAdminDemo extends Command
{
    protected $signature = 'admin:provision-demo';
    protected $description = 'Create a new, zero-permission read-only demo identity; never convert existing accounts';

    public function handle(): int
    {
        if (Admin::query()->where('email', AdminDemoService::EMAIL)->exists()) {
            $this->error('保留Demo身分已存在；不修改任何既有帳號。');
            return self::FAILURE;
        }
        $admin = new Admin(['name' => '唯讀Demo', 'email' => AdminDemoService::EMAIL,
            'password' => Str::random(64), 'status' => 'active']);
        if (! $admin->saveOrFail() || ! $admin->exists) {
            $this->error('無法建立Demo身分。');
            return self::FAILURE;
        }
        $this->info('已建立零權限Demo，ID：'.$admin->id.'。請另行設定ADMIN_DEMO_ID與ADMIN_DEMO_ENABLED。');
        return self::SUCCESS;
    }
}
