<?php

namespace App\Models;

use App\Enums\ConversationSummaryTriggerType;
use App\Enums\ConversationSummaryType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'conversation_id',
    'summary_type',
    'trigger_type',
    'period_start',
    'period_end',
    'message_count',
    'token_count',
    'summary',
    'stats',
])]
class UserConversationSummary extends Model
{
    protected $attributes = [
        'summary_type' => ConversationSummaryType::ConversationCycle->value,
        'trigger_type' => ConversationSummaryTriggerType::Weekly->value,
    ];

    protected function casts(): array
    {
        return [
            'summary_type' => ConversationSummaryType::class,
            'trigger_type' => ConversationSummaryTriggerType::class,
            'period_start' => 'datetime',
            'period_end' => 'datetime',
            'stats' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
