<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Deposit\DepositService;
use App\Http\Middleware\EnforceIdempotency;
use App\Http\Requests\StoreDepositRequest;
use App\Http\Resources\DepositResource;
use Illuminate\Http\JsonResponse;

class DepositController extends Controller
{
    public function store(StoreDepositRequest $request, DepositService $deposits): JsonResponse
    {
        $gateway = $request->string('gateway')->value();

        $deposit = $deposits->create(
            $request->user(),
            $request->integer('wallet_id'),
            $request->integer('amount'),
            $request->string('currency')->value(),
            $gateway === '' ? null : $gateway,
            (string) $request->header(EnforceIdempotency::HEADER),
        );

        return DepositResource::make($deposit)
            ->response()
            ->setStatusCode(201);
    }
}
