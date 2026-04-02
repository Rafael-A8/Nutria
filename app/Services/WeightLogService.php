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

    /**
     * @return array{start_weight: ?float, end_weight: ?float, min_weight: ?float, max_weight: ?float, entries: int}
     */
    public function getPeriodWeights(User $user, Carbon $startDate, Carbon $endDate): array
    {
        $logs = $user->weightLogs()
            ->whereBetween('logged_at', [$startDate->startOfDay(), $endDate->endOfDay()])
            ->orderBy('logged_at')
            ->get();

        return [
            'start_weight' => $logs->first()?->weight_kg,
            'end_weight' => $logs->last()?->weight_kg,
            'min_weight' => $logs->min('weight_kg'),
            'max_weight' => $logs->max('weight_kg'),
            'entries' => $logs->count(),
        ];
    }
}
