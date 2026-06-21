<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

test('the test suite runs against a real mysql connection', function () {
    expect(DB::connection()->getDriverName())->toBe('mysql')
        ->and(DB::connection()->getDatabaseName())->toBe('taline_wallet_task_test');
});

test('migrations applied and a trivial query runs', function () {
    expect(DB::table('users')->count())->toBe(0);
});
