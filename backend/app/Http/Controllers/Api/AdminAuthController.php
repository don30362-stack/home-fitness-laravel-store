<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AdminLoginRequest;
use App\Http\Resources\AdminResource;
use App\Models\Admin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class AdminAuthController extends Controller
{
    public function login(AdminLoginRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $admin = Admin::query()->where('email', $validated['email'])->first();

        if (! $admin || app(\App\Services\AdminDemoService::class)->isDemo($admin)
            || ! Hash::check($validated['password'], $admin->password)) {
            return response()->json(['message' => '管理員電子郵件或密碼錯誤'], 401);
        }

        if ($admin->status !== 'active') {
            return response()->json([
                'code' => 'ADMIN_ACCOUNT_DISABLED',
                'message' => '此管理員帳號已停用，請聯絡管理員',
            ], 403);
        }

        // Laravel 13 SessionGuard::login 已旋轉 ID／CSRF，無須再 regenerate。
        Auth::guard('admin')->login($admin);
        $admin->load(['permissions' => fn ($query) => $query->orderBy('code')]);

        return (new AdminResource($admin))
            ->additional(['message' => '管理員登入成功'])
            ->response();
    }

    public function me(Request $request): JsonResponse
    {
        $admin = $request->user('admin');
        $admin->load(['permissions' => fn ($query) => $query->orderBy('code')]);

        return (new AdminResource($admin))->response();
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('admin')->logout();
        $request->session()->forget('admin_demo_id');
        $request->session()->migrate(true);

        return response()->json(['message' => '管理員登出成功']);
    }
}
