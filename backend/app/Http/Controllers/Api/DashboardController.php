<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\DashboardResource;
use App\Services\DashboardService;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request, DashboardService $service): DashboardResource
    {
        $admin = $request->user('admin');
        return new DashboardResource(app(\App\Services\AdminDemoService::class)->isDemo($admin)
            ? $service->demoSummary() : $service->summary($admin));
    }
}
