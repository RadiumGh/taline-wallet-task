<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Withdrawal\Enums\WithdrawalOutcome;
use App\Domain\Withdrawal\WithdrawalReviewService;
use App\Http\Requests\RejectWithdrawalRequest;
use App\Http\Resources\WithdrawalResource;
use App\Models\Withdrawal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WithdrawalReviewController extends Controller
{
    public function approve(Request $request, Withdrawal $withdrawal, WithdrawalReviewService $reviews): JsonResponse
    {
        return $this->respond($withdrawal, $reviews->approve($withdrawal, $request->user()));
    }

    public function settle(Request $request, Withdrawal $withdrawal, WithdrawalReviewService $reviews): JsonResponse
    {
        return $this->respond($withdrawal, $reviews->settle($withdrawal, $request->user()));
    }

    public function reject(RejectWithdrawalRequest $request, Withdrawal $withdrawal, WithdrawalReviewService $reviews): JsonResponse
    {
        $reason = $request->string('reason')->value();

        return $this->respond($withdrawal, $reviews->reject($withdrawal, $request->user(), $reason === '' ? null : $reason));
    }

    private function respond(Withdrawal $withdrawal, WithdrawalOutcome $outcome): JsonResponse
    {
        return WithdrawalResource::make($withdrawal->refresh())
            ->additional(['result' => $outcome->value])
            ->response()
            ->setStatusCode(200);
    }
}
