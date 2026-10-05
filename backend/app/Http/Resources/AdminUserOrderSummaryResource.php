<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminUserOrderSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_no' => $this->order_no,
            'created_at' => $this->created_at?->toISOString(),
            'total_amount' => $this->total_amount,
            'payment_method' => $this->payment_method,
            'payment_status' => $this->payment_status,
            'order_status' => $this->order_status,
        ];
    }
}
