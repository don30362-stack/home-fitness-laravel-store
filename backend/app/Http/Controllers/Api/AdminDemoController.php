<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\EnterAdminDemoRequest;
use App\Http\Resources\AdminResource;
use App\Services\AdminDemoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class AdminDemoController extends Controller
{
    public function store(EnterAdminDemoRequest $request, AdminDemoService $demo): JsonResponse
    {
        $current = $request->user('admin');
        if ($current && ! $demo->isDemo($current)) {
            return response()->json(['message' => '請先登出目前管理員，再進入唯讀Demo。'], 409);
        }
        $admin = $demo->configuredAdmin();
        if (! $admin) return response()->json(['message' => '唯讀Demo目前無法使用。'], 503);

        Auth::guard('admin')->login($admin);
        $request->session()->put('admin_demo_id', $admin->id);
        $admin->load('permissions');
        return (new AdminResource($admin))->additional(['message' => '已進入唯讀Demo。'])->response();
    }
}
