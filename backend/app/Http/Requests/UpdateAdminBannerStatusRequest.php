<?php

namespace App\Http\Requests;

class UpdateAdminBannerStatusRequest extends AdminBannerRequest
{
    public function rules(): array
    {
        return $this->strictRules(['status' => ['required', 'string', 'in:active,inactive']]);
    }
}
