<?php

declare(strict_types=1);

namespace App\Domain\Ledger;

use App\Models\Deposit;
use App\Models\LedgerEntry;
use App\Models\Transfer;
use App\Models\Wallet;
use Illuminate\Contracts\Pagination\CursorPaginator;

final class WalletTransactionQuery
{
    private const REFERENCE_TYPES = [
        'deposit' => Deposit::class,
        'transfer' => Transfer::class,
    ];

    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginate(Wallet $wallet, array $filters, int $perPage): CursorPaginator
    {
        $query = LedgerEntry::query()->where('wallet_id', $wallet->getKey());

        if (isset($filters['direction'])) {
            EntryDirection::from($filters['direction']) === EntryDirection::Credit
                ? $query->where('amount', '>=', 0)
                : $query->where('amount', '<', 0);
        }

        if (isset($filters['reference_type'])) {
            $query->where('reference_type', self::REFERENCE_TYPES[$filters['reference_type']]);
        }

        if (isset($filters['date_from'])) {
            $query->where('created_at', '>=', $filters['date_from']);
        }

        if (isset($filters['date_to'])) {
            $query->where('created_at', '<=', $filters['date_to']);
        }

        return $query
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->cursorPaginate($perPage);
    }
}
