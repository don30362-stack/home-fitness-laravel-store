<?php

namespace App\Http\Middleware;

use App\Services\AdminDemoService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdminDemoAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $demo = app(AdminDemoService::class);
        if ($demo->isDemo($request->user('admin'))) {
            $route = $request->route();
            $sessionAction = $request->method() === 'POST' && in_array($route?->getActionName(), [
                'App\\Http\\Controllers\\Api\\AdminAuthController@logout',
                'App\\Http\\Controllers\\Api\\AdminDemoController@store',
            ], true);
            // Entry retains its own 503 contract; logout must remain available.
            if (! $sessionAction && ! config('demo.enabled')) return response()->json([
                'code' => 'DEMO_UNAVAILABLE', 'message' => '唯讀Demo目前暫停開放，您仍可登出。',
            ], 403);
            if (! $sessionAction && ! $demo->allowsRead($request)) return response()->json([
                'code' => 'DEMO_READ_ONLY', 'message' => '唯讀Demo不允許此操作。',
            ], 403);
        }
        return $next($request);
    }
}
