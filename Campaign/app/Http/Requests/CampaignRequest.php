<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use App\Rules\VariantsProportionSum;

class CampaignRequest extends FormRequest
{

    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'name' => 'required|max:255',
            'active' => 'boolean|required',
            'banner_id' => 'required|integer',
            'signed_in' => 'boolean|nullable',
            'using_adblock' => 'boolean|nullable',
            'once_per_session' => 'boolean|required',
            'segments' => 'array',
            'pageview_rules.display_banner' => 'required|string',
            'pageview_rules.display_banner_every' => 'required|integer',
            'pageview_rules.display_times' => 'required',
            'pageview_rules.display_n_times' => 'required|integer',
            'pageview_rules.after_banner_closed_display' => 'required|string',
            'pageview_rules.after_closed_hours' => 'required|integer',
            'pageview_rules.after_banner_clicked_display' => 'required|string',
            'pageview_rules.after_clicked_hours' => 'required|integer',
            'pageview_attributes.*.name' => 'required|string',
            'pageview_attributes.*.operator' => 'required|string',
            'pageview_attributes.*.value' => 'required|string',
            'url_patterns.*' => 'string',
            'referer_patterns.*' => 'string',
            'devices.0' => 'required',
            'variants.*.proportion' => 'integer|required|min:0|max:100',
            'variants.*.control_group' => 'integer|required',
            'variants.*.weight' => 'integer|required',
            'variants.*.banner_id' => 'required_unless:variants.*.control_group,1',
            'variants.0.proportion' => ['integer', 'required', new VariantsProportionSum]
        ];
    }

    public function all($keys = null)
    {
        $data = parent::all($keys);
        if (!isset($data['signed_in'])) {
            $data['signed_in'] = null;
        }
        if (!isset($data['using_adblock'])) {
            $data['using_adblock'] = null;
        }
        if (!isset($data['pageview_rules']['display_times'])) {
            $data['pageview_rules']['display_times'] = false;
        }
        if (is_array($data['url_patterns'])) {
            $data['url_patterns'] = array_values(array_filter($data['url_patterns']));
        }
        if (is_array($data['referer_patterns'])) {
            $data['referer_patterns'] = array_values(array_filter($data['referer_patterns']));
        }
        $data['pageview_rules']['display_times'] = filter_var(
            $data['pageview_rules']['display_times'],
            FILTER_VALIDATE_BOOLEAN,
            ['options' => ['default' => false]]
        );
        $data['pageview_rules']['display_banner_every'] = filter_var(
            $data['pageview_rules']['display_banner_every'],
            FILTER_VALIDATE_INT,
            ['options' => ['default' => 2]]
        );
        $data['pageview_rules']['display_n_times'] = filter_var(
            $data['pageview_rules']['display_n_times'],
            FILTER_VALIDATE_INT,
            ['options' => ['default' => 2]]
        );
        $data['pageview_rules']['after_closed_hours'] = filter_var(
            $data['pageview_rules']['after_closed_hours'],
            FILTER_VALIDATE_INT,
            ['options' => ['default' => 2]]
        );
        $data['pageview_rules']['after_clicked_hours'] = filter_var(
            $data['pageview_rules']['after_clicked_hours'],
            FILTER_VALIDATE_INT,
            ['options' => ['default' => 2]]
        );
        $data['active'] = $this->getInputSource()->getBoolean('active', false);
        $data['once_per_session'] = $this->getInputSource()->getBoolean('once_per_session', false);
        return $data;
    }
}
