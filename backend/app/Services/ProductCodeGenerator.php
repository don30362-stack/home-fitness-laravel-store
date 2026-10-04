<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Validation\ValidationException;

class ProductCodeGenerator
{
    public function generate(): string
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $code = $this->candidate();
            if (! Product::query()->where('product_code', $code)->exists()) {
                return $code;
            }
        }

        throw ValidationException::withMessages(['product_code' => '暫時無法產生商品編號，請稍後再試。']);
    }

    // 可由隔離測試替換候選來源，正式使用密碼學安全亂數。
    protected function candidate(): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $suffix = '';
        for ($i = 0; $i < 8; $i++) {
            $suffix .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return 'PRD-'.$suffix;
    }
}
