<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Outbox\OutboxRelay;
use Illuminate\Console\Command;

class RelayOutboxEvents extends Command
{
    protected $signature = 'outbox:relay';

    protected $description = 'Relay pending transactional outbox events to their consumers.';

    public function handle(OutboxRelay $relay): int
    {
        $published = $relay->relay((int) config('wallet.outbox.batch_size'));

        $this->info("Relayed {$published} outbox event(s).");

        return self::SUCCESS;
    }
}
