<?php

namespace App\Http\Requests;

class UpdateAdminProductImageRequest extends StoreAdminProductImageRequest
{
    public function rules(): array
    {
        $rules = parent::rules();
        $rules['image'] = ['missing'];

        return $rules;
    }
}
