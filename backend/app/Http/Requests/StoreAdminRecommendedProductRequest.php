<?php

namespace App\Http\Requests;

class StoreAdminRecommendedProductRequest extends AdminRecommendedProductRequest
{
    public function rules(): array
    {
        return ['product_id' => ['required', 'integer', 'min:1', 'exists:products,id']];
    }
}
