<?php

declare(strict_types=1);

namespace App\Domain\Observability;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Context;

final class AuditLogger
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function record(string $action, Model $subject, array $context = [], ?Model $actor = null): AuditLog
    {
        $log = new AuditLog([
            'action' => $action,
            'context' => $context,
            'request_id' => Context::get('request_id'),
            'ip' => request()->ip(),
        ]);

        $log->subject()->associate($subject);

        if ($actor !== null) {
            $log->actor()->associate($actor);
        }

        $log->save();

        return $log;
    }
}
