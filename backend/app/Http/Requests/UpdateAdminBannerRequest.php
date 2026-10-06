<?php

namespace App\Http\Requests;

class UpdateAdminBannerRequest extends AdminBannerRequest
{
    public function rules(): array { return $this->bannerRules(false); }
}
