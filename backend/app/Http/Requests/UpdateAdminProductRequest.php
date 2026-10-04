<?php

namespace App\Http\Requests;

class UpdateAdminProductRequest extends StoreAdminProductRequest
{
    public function rules(): array
    {
        $rules = parent::rules();
        foreach (['category_id', 'name', 'price'] as $field) {
            array_unshift($rules[$field], 'sometimes');
        }
        $rules['stock'] = ['missing'];
        $rules['status'] = ['missing'];
        $rules['variants'] = ['sometimes', 'array'];
        $rules['variants.*'] = ['array:id,option_name,option_value,stock,status'];
        $rules['variants.*.id'] = ['sometimes', 'required', 'integer', 'min:1', 'distinct'];
        $rules['variants.*.stock'] = ['sometimes', 'required', 'integer', 'min:0', 'max:4294967295'];
        // existing的stock連同原值也不可提交；new row才有初始庫存。
        foreach ((array) $this->input('variants', []) as $index => $row) {
            if (! is_array($row)) {
                continue;
            }
            $rules["variants.$index.stock"] = array_key_exists('id', $row)
                ? ['missing'] : ['required', 'integer', 'min:0', 'max:4294967295'];
        }

        return $rules;
    }
}
