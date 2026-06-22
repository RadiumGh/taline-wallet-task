<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Transfer\TransferService;
use App\Http\Middleware\EnforceIdempotency;
use App\Http\Requests\StoreTransferRequest;
use App\Http\Resources\TransferResource;
use Illuminate\Http\JsonResponse;

class TransferController extends Controller
{
    public function store(StoreTransferRequest $request, TransferService $transfers): JsonResponse
    {
        $transfer = $transfers->transfer(
            $request->user(),
            $request->integer('to_wallet_id'),
            $request->integer('amount'),
            $request->string('currency')->value(),
            (string) $request->header(EnforceIdempotency::HEADER),
        );

        return TransferResource::make($transfer)
            ->response()
            ->setStatusCode(201);
    }
}
