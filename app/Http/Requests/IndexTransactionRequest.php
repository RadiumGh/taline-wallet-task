<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexTransactionRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'cursor' => ['sometimes', 'string'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'direction' => ['sometimes', Rule::in(['credit', 'debit'])],
            'reference_type' => ['sometimes', Rule::in(['deposit', 'transfer'])],
            'date_from' => ['sometimes', 'date'],
            'date_to' => ['sometimes', 'date'],
        ];
    }
}
