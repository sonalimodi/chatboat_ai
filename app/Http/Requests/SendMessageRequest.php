<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SendMessageRequest extends FormRequest
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
            'message' => ['required', 'string', 'max:4000'],
            // Which provider/model the user picked in the UI. Optional -
            // if omitted or not currently usable, the normal fallback
            // order (config('llm.order')) is used instead.
            'model' => ['nullable', 'string', Rule::in(array_keys(config('llm.providers', [])))],
            // 'mimes' validates the actual detected file content type, not
            // just the extension/client-provided Content-Type header.
            'attachment' => [
                'nullable',
                'file',
                'mimes:pdf,txt',
                'max:' . config('attachments.max_size_kb'),
            ],
        ];
    }
}
