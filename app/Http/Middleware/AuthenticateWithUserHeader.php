<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateWithUserHeader
{
    public const HEADER = 'X-User-Id';

    public function handle(Request $request, Closure $next): Response
    {
        $userId = $request->header(self::HEADER);

        if (! is_string($userId) || ! ctype_digit($userId)) {
            abort(401, 'Missing or invalid '.self::HEADER.' header.');
        }

        $user = User::query()->find((int) $userId);

        if ($user === null) {
            abort(401, 'Unknown user.');
        }

        auth()->setUser($user);

        return $next($request);
    }
}
