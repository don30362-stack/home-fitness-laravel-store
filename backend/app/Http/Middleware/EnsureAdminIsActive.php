<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdminIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        // 接在 auth:admin 後；即使 guard 已快取身分，也重新查詢 DB 狀態。
        $admin = $request->user('admin')->fresh();

        if (! $admin || $admin->status !== 'active') {
            Auth::guard('admin')->logout();
            $request->session()->migrate(true);

            return response()->json([
                'code' => 'ADMIN_ACCOUNT_DISABLED',
                'message' => '此管理員帳號已停用，請聯絡管理員',
            ], 403);
        }

        Auth::guard('admin')->setUser($admin);

        return $next($request);
    }
}
