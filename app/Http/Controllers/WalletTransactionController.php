<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Ledger\WalletTransactionQuery;
use App\Domain\Wallet\Exceptions\WalletNotFoundException;
use App\Http\Requests\IndexTransactionRequest;
use App\Http\Resources\LedgerEntryResource;
use App\Models\Wallet;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class WalletTransactionController extends Controller
{
    public function index(IndexTransactionRequest $request, Wallet $wallet, WalletTransactionQuery $transactions): AnonymousResourceCollection
    {
        if ($request->user()->cannot('view', $wallet)) {
            throw WalletNotFoundException::forOwnedWallet($request->user()->getKey(), $wallet->getKey());
        }

        $page = $transactions->paginate(
            $wallet,
            $request->validated(),
            $request->integer('per_page', 25),
        );

        return LedgerEntryResource::collection($page);
    }
}
