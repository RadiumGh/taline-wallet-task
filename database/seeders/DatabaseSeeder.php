<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use App\Models\Wallet;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $alice = User::factory()->create([
            'name' => 'Alice',
            'email' => 'alice@example.com',
        ]);

        $bob = User::factory()->create([
            'name' => 'Bob',
            'email' => 'bob@example.com',
        ]);

        Wallet::factory()->for($alice)->create();
        Wallet::factory()->for($bob)->create();
    }
}
