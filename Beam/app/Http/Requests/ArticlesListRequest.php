<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ArticlesListRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    protected function prepareForValidation()
    {
        $this->replace([
            'external_ids' => array_filter(explode(',', $this->get('external_ids'))),
            'ids' => array_filter(explode(',', $this->get('ids'))),
        ]);
    }


    public function rules()
    {
        // TODO: add prohibited_if rule for each field after update to Laravel 8
        return [
            'external_ids' => "array",
            'ids' => "array",
        ];
    }
}
