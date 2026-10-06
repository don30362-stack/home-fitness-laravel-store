<?php

namespace App\Http\Requests;

class ReorderAdminRecommendedProductsRequest extends AdminRecommendedProductRequest
{
    public function rules(): array
    {
        return ['ids' => ['present', 'array', 'list'], 'ids.*' => ['required', 'integer', 'min:1', 'distinct']];
    }
}
