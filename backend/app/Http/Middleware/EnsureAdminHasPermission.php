<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdminHasPermission
{
    public function handle(Request $request, Closure $next, string ...$codes): Response
    {
        $admin = $request->user('admin');
        // Always query the relation: loaded identity/session data is not authority.
        if (! $admin || ! $admin->permissions()->whereIn('code', $codes)->exists()) {
            return response()->json([
                'message' => '您沒有此功能的操作權限。',
                'code' => 'ADMIN_PERMISSION_DENIED',
            ], 403);
        }

        return $next($request);
    }
}
