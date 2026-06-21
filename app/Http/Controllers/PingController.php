<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Middleware\AssignRequestId;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PingController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        return response()->json([
            'user_id' => $request->user()->getKey(),
            'request_id' => $request->header(AssignRequestId::HEADER),
        ]);
    }
}
