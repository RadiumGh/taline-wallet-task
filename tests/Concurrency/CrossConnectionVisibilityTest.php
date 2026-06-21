<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

function secondConnection(): ConnectionInterface
{
    config()->set('database.connections.mysql_concurrency', config('database.connections.mysql'));
    DB::purge('mysql_concurrency');

    return DB::connection('mysql_concurrency');
}

afterEach(function (): void {
    $this->truncateTablesForAllConnections();
});

test('rows committed on one connection are visible to a separate connection', function () {
    $user = User::factory()->create();

    $seenOnOtherConnection = secondConnection()
        ->table('users')
        ->where('id', $user->getKey())
        ->exists();

    expect($seenOnOtherConnection)->toBeTrue();
});
