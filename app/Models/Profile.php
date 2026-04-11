<?php

namespace App\Models;

use App\Enums\AiModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'gender', 'birth_date', 'height_cm', 'goal', 'activity_level', 'preferred_ai_model', 'custom_instructions'])]
class Profile extends Model
{
    /** @var array<string, mixed> */
    protected $attributes = [
        'preferred_ai_model' => AiModel::GeminiFlashLite->value,
    ];

    use HasFactory;

    protected $table = 'user_profiles';

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
