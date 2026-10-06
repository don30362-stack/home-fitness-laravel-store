<?php

namespace Database\Seeders;

use App\Models\Permission;
use Illuminate\Database\Seeder;

class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        foreach (Permission::CATALOG as $code => $name) {
            Permission::query()->updateOrCreate(['code' => $code], ['name' => $name]);
        }
    }
}
