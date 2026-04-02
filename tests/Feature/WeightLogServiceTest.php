<?php

use App\Models\User;
use App\Services\WeightLogService;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->service = new WeightLogService;
});

it('logs a weight entry', function () {
    $log = $this->service->log($this->user, 75.5);

    expect($log)
        ->user_id->toBe($this->user->id)
        ->weight_kg->toEqual(75.50);

    $this->assertDatabaseHas('weight_logs', [
        'id' => $log->id,
        'user_id' => $this->user->id,
    ]);
});

it('logs with custom date', function () {
    $date = Carbon::parse('2026-03-15 08:00:00');
    $log = $this->service->log($this->user, 74.0, $date);

    expect($log->logged_at->toDateTimeString())->toBe('2026-03-15 08:00:00');
});

it('gets latest weight', function () {
    $this->service->log($this->user, 80.0, Carbon::parse('2026-03-01'));
    $this->service->log($this->user, 78.5, Carbon::parse('2026-03-15'));
    $this->service->log($this->user, 77.0, Carbon::parse('2026-04-01'));

    $latest = $this->service->getLatestWeight($this->user);

    expect($latest)->toEqual(77.00);
});

it('returns null when no weight logs exist', function () {
    $latest = $this->service->getLatestWeight($this->user);

    expect($latest)->toBeNull();
});

it('does not return weight from another user', function () {
    $otherUser = User::factory()->create();
    $this->service->log($otherUser, 90.0);

    $latest = $this->service->getLatestWeight($this->user);

    expect($latest)->toBeNull();
});
