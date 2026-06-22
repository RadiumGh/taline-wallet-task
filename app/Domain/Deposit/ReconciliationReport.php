<?php

declare(strict_types=1);

namespace App\Domain\Deposit;

final readonly class ReconciliationReport
{
    public function __construct(
        public int $confirmed,
        public int $failed,
        public int $skipped,
    ) {}
}
