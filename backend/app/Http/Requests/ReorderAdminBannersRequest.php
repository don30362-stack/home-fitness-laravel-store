<?php

namespace App\Http\Requests;

class ReorderAdminBannersRequest extends AdminBannerRequest
{
    public function rules(): array
    {
        // present permits [] only; the locked current set is checked by the service.
        return $this->strictRules([
            'ids' => ['present', 'array', 'list'],
            'ids.*' => ['required', 'integer', 'min:1', 'distinct'],
        ]);
    }
}
