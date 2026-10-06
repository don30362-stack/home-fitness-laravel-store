<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['code', 'name'])]
class Permission extends Model
{
    public $timestamps = false;

    public const CATALOG = [
        'product_manage' => '商品管理',
        'category_manage' => '商品分類管理',
        'inventory_manage' => '庫存管理',
        'order_manage' => '訂單管理',
        'member_manage' => '會員管理',
        'home_content_manage' => '首頁內容管理',
        'admin_manage' => '管理員管理',
    ];

    /** @return BelongsToMany<Admin, $this> */
    public function admins(): BelongsToMany
    {
        return $this->belongsToMany(Admin::class, 'admin_permission');
    }
}
