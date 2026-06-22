<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreGatewayCallbackRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'event_id' => ['required', 'string'],
            'gateway' => ['sometimes', 'nullable', 'string'],
            'gateway_reference' => ['sometimes', 'nullable', 'string'],
        ];
    }
}
