<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\MoneyCast;
use App\Domain\Wallet\Enums\WalletType;
use Database\Factories\WalletFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Wallet extends Model
{
    /** @use HasFactory<WalletFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'type',
        'code',
        'currency',
    ];

    protected $attributes = [
        'type' => 'user',
        'balance' => 0,
        'version' => 0,
    ];

    protected function casts(): array
    {
        return [
            'type' => WalletType::class,
            'balance' => MoneyCast::class,
            'version' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
