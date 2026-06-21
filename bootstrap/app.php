<?php

use App\Exceptions\Contracts\HasHttpStatus;
use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\AuthenticateWithUserHeader;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(append: [
            AssignRequestId::class,
        ]);

        $middleware->alias([
            'auth.header' => AuthenticateWithUserHeader::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        $exceptions->render(function (HasHttpStatus $e, Request $request): ?JsonResponse {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'error' => $e->errorCode(),
                'message' => $e->getMessage(),
            ], $e->httpStatus());
        });
    })->create();
