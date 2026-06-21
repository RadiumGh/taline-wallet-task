<?php

declare(strict_types=1);

use App\Models\User;

test('ping returns the resolved user id and a request id with the correlation header', function () {
    $user = User::factory()->create();

    $response = $this->withHeader('X-User-Id', (string) $user->getKey())->getJson('/api/ping');

    $response->assertOk()
        ->assertJsonPath('user_id', $user->getKey());

    expect($response->json('request_id'))->not->toBeEmpty();
    expect($response->headers->get('X-Request-Id'))->toBe($response->json('request_id'));
});

test('ping echoes back a caller supplied request id', function () {
    $user = User::factory()->create();

    $response = $this->withHeaders([
        'X-User-Id' => (string) $user->getKey(),
        'X-Request-Id' => 'corr-12345',
    ])->getJson('/api/ping');

    $response->assertOk()->assertJsonPath('request_id', 'corr-12345');
    expect($response->headers->get('X-Request-Id'))->toBe('corr-12345');
});

test('ping rejects a request without an X-User-Id header as 401 json', function () {
    $response = $this->getJson('/api/ping');

    $response->assertUnauthorized()
        ->assertHeader('content-type', 'application/json');
});

test('ping rejects an unknown user as 401', function () {
    $response = $this->withHeader('X-User-Id', '999999')->getJson('/api/ping');

    $response->assertUnauthorized();
});
