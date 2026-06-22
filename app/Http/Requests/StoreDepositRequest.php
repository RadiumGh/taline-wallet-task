<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDepositRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'wallet_id' => ['required', 'integer', 'exists:wallets,id'],
            'amount' => ['required', 'integer', 'min:1'],
            'currency' => ['required', 'string', Rule::in(array_keys(config('wallet.currencies')))],
            'gateway' => ['sometimes', 'nullable', 'string'],
        ];
    }
}
