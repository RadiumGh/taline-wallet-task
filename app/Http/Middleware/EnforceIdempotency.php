<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Idempotency\Exceptions\IdempotencyConflictException;
use App\Domain\Idempotency\Exceptions\MissingIdempotencyKeyException;
use App\Domain\Idempotency\IdempotencyStatus;
use App\Models\IdempotencyKey;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnforceIdempotency
{
    public const HEADER = 'Idempotency-Key';

    private const CLAIM_ATTRIBUTE = 'idempotency_claim_id';

    public function handle(Request $request, Closure $next): Response
    {
        $key = $this->readKey($request);
        $scope = 'user:'.$request->user()->getKey();
        $requestHash = $this->hashRequest($request);

        $existing = $this->find($scope, $key);

        if ($existing !== null && $this->isExpired($existing)) {
            $existing->delete();
            $existing = null;
        }

        if ($existing !== null) {
            return $this->replayOrReject($existing, $requestHash);
        }

        $claim = $this->claim($scope, $key, $requestHash, $request);

        if ($claim === null) {
            return $this->replayOrReject($this->find($scope, $key), $requestHash);
        }

        $request->attributes->set(self::CLAIM_ATTRIBUTE, $claim->getKey());

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $claimId = $request->attributes->get(self::CLAIM_ATTRIBUTE);

        if ($claimId === null) {
            return;
        }

        $claim = IdempotencyKey::query()->find($claimId);

        if ($claim === null) {
            return;
        }

        if ($response->getStatusCode() >= 500) {
            $claim->delete();

            return;
        }

        $claim->update([
            'status' => IdempotencyStatus::Completed,
            'response_status' => $response->getStatusCode(),
            'response_body' => $this->decodeBody($response),
        ]);
    }

    private function readKey(Request $request): string
    {
        $key = $request->header(self::HEADER);

        if (! is_string($key) || trim($key) === '') {
            throw MissingIdempotencyKeyException::make();
        }

        return trim($key);
    }

    private function find(string $scope, string $key): ?IdempotencyKey
    {
        return IdempotencyKey::query()
            ->where('scope', $scope)
            ->where('key', $key)
            ->first();
    }

    private function replayOrReject(?IdempotencyKey $row, string $requestHash): Response
    {
        if ($row === null) {
            throw IdempotencyConflictException::inFlight();
        }

        if (! hash_equals($row->request_hash, $requestHash)) {
            throw IdempotencyConflictException::keyReused();
        }

        if ($row->status === IdempotencyStatus::Processing) {
            throw IdempotencyConflictException::inFlight();
        }

        return response()->json($row->response_body, $row->response_status ?? Response::HTTP_OK);
    }

    private function claim(string $scope, string $key, string $requestHash, Request $request): ?IdempotencyKey
    {
        try {
            return IdempotencyKey::query()->create([
                'scope' => $scope,
                'key' => $key,
                'request_hash' => $requestHash,
                'method' => $request->method(),
                'path' => $request->path(),
                'status' => IdempotencyStatus::Processing,
                'locked_at' => now(),
                'expires_at' => now()->addMinutes((int) config('wallet.idempotency.ttl_minutes')),
            ]);
        } catch (QueryException $e) {
            if ($this->isUniqueViolation($e)) {
                return null;
            }

            throw $e;
        }
    }

    private function hashRequest(Request $request): string
    {
        return hash('sha256', $request->method().'|'.$request->path().'|'.$request->getContent());
    }

    private function isExpired(IdempotencyKey $row): bool
    {
        return $row->expires_at !== null && $row->expires_at->isPast();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeBody(Response $response): ?array
    {
        $content = $response->getContent();

        if (! is_string($content) || $content === '') {
            return null;
        }

        $decoded = json_decode($content, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        return (string) $e->getCode() === '23000';
    }
}
