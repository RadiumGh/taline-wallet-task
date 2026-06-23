<?php

declare(strict_types=1);

use App\Domain\Deposit\Enums\DepositStatus;

test('a pending deposit may move to confirmed or failed', function () {
    expect(DepositStatus::Pending->canTransitionTo(DepositStatus::Confirmed))->toBeTrue()
        ->and(DepositStatus::Pending->canTransitionTo(DepositStatus::Failed))->toBeTrue();
});

test('a pending deposit may not transition to itself', function () {
    expect(DepositStatus::Pending->canTransitionTo(DepositStatus::Pending))->toBeFalse();
});

test('terminal deposit states reject every further transition', function () {
    expect(DepositStatus::Confirmed->canTransitionTo(DepositStatus::Failed))->toBeFalse()
        ->and(DepositStatus::Confirmed->canTransitionTo(DepositStatus::Pending))->toBeFalse()
        ->and(DepositStatus::Confirmed->canTransitionTo(DepositStatus::Confirmed))->toBeFalse()
        ->and(DepositStatus::Failed->canTransitionTo(DepositStatus::Confirmed))->toBeFalse()
        ->and(DepositStatus::Failed->canTransitionTo(DepositStatus::Pending))->toBeFalse()
        ->and(DepositStatus::Failed->canTransitionTo(DepositStatus::Failed))->toBeFalse();
});
