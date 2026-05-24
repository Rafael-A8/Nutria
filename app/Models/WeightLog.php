<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'weight_kg', 'logged_at'])]
class WeightLog extends Model
{
    protected function casts(): array
    {
        return [
            'logged_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->logged_at)) {
                $model->logged_at = now();
            }
        });
    }
}
