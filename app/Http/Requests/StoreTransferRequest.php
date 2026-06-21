<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTransferRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'to_wallet_id' => ['required', 'integer', 'exists:wallets,id'],
            'amount' => ['required', 'integer', 'min:1'],
            'currency' => ['required', 'string', Rule::in(array_keys(config('wallet.currencies')))],
        ];
    }
}
