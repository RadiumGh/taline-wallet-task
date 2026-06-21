<?php

declare(strict_types=1);

namespace App\Exceptions\Contracts;

use Throwable;

interface HasHttpStatus extends Throwable
{
    public function httpStatus(): int;

    public function errorCode(): string;
}
