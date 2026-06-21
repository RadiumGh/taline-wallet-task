<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\MoneyCast;
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
        'currency',
    ];

    protected $attributes = [
        'balance' => 0,
        'version' => 0,
    ];

    protected function casts(): array
    {
        return [
            'balance' => MoneyCast::class,
            'version' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
