<?php

namespace App\Http\Requests;

class StoreAdminBannerRequest extends AdminBannerRequest
{
    public function rules(): array { return $this->bannerRules(true); }
}
