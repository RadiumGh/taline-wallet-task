<?php

use App\Domain\Money\ValueObjects\Money;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)
    ->in('Unit');

pest()->extend(TestCase::class)
    ->use(DatabaseTruncation::class)
    ->in('Concurrency');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function runInParallel(int $workers, Closure $task): void
{
    $startAt = microtime(true) + 0.3;
    $pids = [];

    for ($i = 0; $i < $workers; $i++) {
        $pid = pcntl_fork();

        if ($pid === -1) {
            throw new RuntimeException('Unable to fork a worker process.');
        }

        if ($pid === 0) {
            DB::purge();

            $remaining = $startAt - microtime(true);
            if ($remaining > 0) {
                usleep((int) ($remaining * 1_000_000));
            }

            try {
                $task($i);
            } catch (Throwable) {
            }

            posix_kill(getmypid(), SIGKILL);
        }

        $pids[] = $pid;
    }

    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $status);
    }
}

function fundedWallet(User $owner, int $balance, string $currency = 'IRR'): Wallet
{
    $wallet = Wallet::factory()->for($owner)->create(['currency' => $currency]);
    $wallet->balance = Money::of($balance, $currency);
    $wallet->save();

    return $wallet->refresh();
}
