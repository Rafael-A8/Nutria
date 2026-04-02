<?php

namespace App\Services;

use App\Models\User;
use App\Models\WeightLog;
use Illuminate\Support\Carbon;

class WeightLogService
{
    public function log(User $user, float $weightKg, ?Carbon $loggedAt = null): WeightLog
    {
        return $user->weightLogs()->create([
            'weight_kg' => $weightKg,
            'logged_at' => $loggedAt ?? Carbon::now(),
        ]);
    }

    public function getLatestWeight(User $user): ?float
    {
        return $user->weightLogs()
            ->latest('logged_at')
            ->value('weight_kg');
    }
}
