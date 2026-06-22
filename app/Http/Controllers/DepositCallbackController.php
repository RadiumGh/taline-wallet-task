<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Deposit\CallbackOutcome;
use App\Domain\Deposit\DepositCallbackService;
use App\Domain\Deposit\GatewayCallbackData;
use App\Http\Requests\StoreGatewayCallbackRequest;
use App\Http\Resources\DepositResource;
use App\Models\Deposit;
use Illuminate\Http\JsonResponse;

class DepositCallbackController extends Controller
{
    public function confirm(StoreGatewayCallbackRequest $request, Deposit $deposit, DepositCallbackService $callbacks): JsonResponse
    {
        return $this->respond($deposit, $callbacks->confirm($deposit, $this->data($request, $deposit)));
    }

    public function fail(StoreGatewayCallbackRequest $request, Deposit $deposit, DepositCallbackService $callbacks): JsonResponse
    {
        return $this->respond($deposit, $callbacks->fail($deposit, $this->data($request, $deposit)));
    }

    private function data(StoreGatewayCallbackRequest $request, Deposit $deposit): GatewayCallbackData
    {
        $gateway = $request->string('gateway')->value();
        $reference = $request->string('gateway_reference')->value();

        return new GatewayCallbackData(
            rawPayload: (string) $request->getContent(),
            signature: (string) $request->header('X-Gateway-Signature', ''),
            gateway: $gateway === '' ? $deposit->gateway : $gateway,
            eventId: $request->string('event_id')->value(),
            gatewayReference: $reference === '' ? null : $reference,
            payload: $request->all(),
        );
    }

    private function respond(Deposit $deposit, CallbackOutcome $outcome): JsonResponse
    {
        return DepositResource::make($deposit->refresh())
            ->additional(['result' => $outcome->value])
            ->response()
            ->setStatusCode(200);
    }
}
