<?php

namespace App\Services;

use App\Models\Admin;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;

class DashboardService
{
    public function summary(Admin $admin): array
    {
        // A loaded identity relation is not authority: read the current DB grants once.
        $codes = $admin->permissions()->whereIn('code', [
            'product_manage', 'member_manage', 'order_manage',
        ])->pluck('code');

        return [
            'products' => $codes->contains('product_manage') ? ['total' => Product::query()->count()] : null,
            'members' => $codes->contains('member_manage') ? ['total' => User::query()->count()] : null,
            'orders' => $codes->contains('order_manage') ? $this->orders() : null,
        ];
    }

    private function orders(): array
    {
        // Near-real-time summary, not a transaction snapshot or a purchase decision.
        $stats = DB::table('orders')->selectRaw('COUNT(*) AS total')
            ->selectRaw('COALESCE(SUM(CASE WHEN order_status = ? THEN 1 ELSE 0 END), 0) AS pending', ['pending'])
            ->selectRaw('COALESCE(SUM(CASE WHEN order_status = ? THEN 1 ELSE 0 END), 0) AS awaiting_shipment', ['processing'])
            ->selectRaw('COALESCE(SUM(CASE WHEN order_status = ? THEN total_amount ELSE 0 END), 0) AS completed_order_amount', ['completed'])
            ->first();

        return [
            'total' => (int) $stats->total,
            'pending' => (int) $stats->pending,
            'awaiting_shipment' => (int) $stats->awaiting_shipment,
            // Match Laravel's decimal cast; do not convert a DB decimal string to float.
            'completed_order_amount' => (string) BigDecimal::of((string) $stats->completed_order_amount)
                ->toScale(2, RoundingMode::HalfUp),
            'recent_orders' => Order::query()->select([
                'id', 'order_no', 'created_at', 'total_amount', 'order_status', 'payment_status',
            ])->orderByDesc('created_at')->orderByDesc('id')->limit(5)->get(),
        ];
    }
}
