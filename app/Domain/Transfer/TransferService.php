<?php

declare(strict_types=1);

namespace App\Domain\Transfer;

use App\Domain\Ledger\LedgerLeg;
use App\Domain\Ledger\LedgerService;
use App\Domain\Money\Money;
use App\Domain\Observability\OperationRecorder;
use App\Domain\Outbox\OutboxRecorder;
use App\Domain\Transfer\Exceptions\SelfTransferException;
use App\Domain\Wallet\Exceptions\WalletNotFoundException;
use App\Models\Transfer;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class TransferService
{
    public function __construct(
        private readonly LedgerService $ledger,
        private readonly OutboxRecorder $outbox,
        private readonly OperationRecorder $recorder,
    ) {}

    public function transfer(User $sender, int $toWalletId, int $amount, string $currency, string $idempotencyKey): Transfer
    {
        $from = $this->resolveSenderWallet($sender, $currency);
        $to = Wallet::query()->findOrFail($toWalletId);

        if ($from->is($to)) {
            throw SelfTransferException::make();
        }

        $existing = $this->findByKey($from, $idempotencyKey);

        if ($existing !== null) {
            return $existing;
        }

        $money = Money::of($amount, $currency);

        try {
            return DB::transaction(function () use ($sender, $from, $to, $money, $idempotencyKey): Transfer {
                $transfer = Transfer::create([
                    'reference' => (string) Str::uuid(),
                    'from_wallet_id' => $from->getKey(),
                    'to_wallet_id' => $to->getKey(),
                    'amount' => $money,
                    'status' => TransferStatus::Completed,
                    'idempotency_key' => $idempotencyKey,
                ]);

                $this->ledger->post($transfer, [
                    LedgerLeg::debit($from, $money),
                    LedgerLeg::credit($to, $money),
                ]);

                $this->outbox->record($transfer, 'transfer.completed', "transfer.completed:{$transfer->getKey()}", [
                    'reference' => $transfer->reference,
                    'from_wallet_id' => $transfer->from_wallet_id,
                    'to_wallet_id' => $transfer->to_wallet_id,
                    'amount' => $money->amount,
                    'currency' => $money->currency->code,
                ]);

                $this->recorder->transferCompleted($transfer, $sender);

                return $transfer;
            }, attempts: 3);
        } catch (UniqueConstraintViolationException $e) {
            return $this->findByKey($from, $idempotencyKey) ?? throw $e;
        }
    }

    private function findByKey(Wallet $from, string $idempotencyKey): ?Transfer
    {
        return Transfer::query()
            ->where('from_wallet_id', $from->getKey())
            ->where('idempotency_key', $idempotencyKey)
            ->first();
    }

    private function resolveSenderWallet(User $sender, string $currency): Wallet
    {
        $wallet = Wallet::query()
            ->where('user_id', $sender->getKey())
            ->where('currency', $currency)
            ->first();

        if ($wallet === null) {
            throw WalletNotFoundException::forOwnerCurrency($sender->getKey(), $currency);
        }

        return $wallet;
    }
}
