<?php

declare(strict_types=1);

namespace App\Domain\Money;

use App\Domain\Money\Exceptions\UnknownCurrencyException;

final readonly class Currency
{
    private function __construct(
        public string $code,
        public int $scale,
    ) {}

    public static function of(string $code): self
    {
        $code = strtoupper($code);

        $scale = config("wallet.currencies.{$code}.scale");

        if (! is_int($scale)) {
            throw UnknownCurrencyException::forCode($code);
        }

        return new self($code, $scale);
    }

    public function equals(self $other): bool
    {
        return $this->code === $other->code;
    }
}
