<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Withdrawal\WithdrawalService;
use App\Http\Middleware\EnforceIdempotency;
use App\Http\Requests\StoreWithdrawalRequest;
use App\Http\Resources\WithdrawalResource;
use Illuminate\Http\JsonResponse;

class WithdrawalController extends Controller
{
    public function store(StoreWithdrawalRequest $request, WithdrawalService $withdrawals): JsonResponse
    {
        $withdrawal = $withdrawals->request(
            $request->user(),
            $request->integer('wallet_id'),
            $request->integer('amount'),
            $request->string('currency')->value(),
            (string) $request->header(EnforceIdempotency::HEADER),
        );

        return WithdrawalResource::make($withdrawal)
            ->response()
            ->setStatusCode(201);
    }
}
