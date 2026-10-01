<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureMemberIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        // 此 middleware 接在 auth:sanctum 後，拒絕停用會員並撤銷本次登入。
        if ($request->user()->status !== 'active') {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return response()->json([
                'code' => 'ACCOUNT_DISABLED',
                'message' => '此會員帳號已停用，請聯絡管理員',
            ], 403);
        }

        return $next($request);
    }
}
