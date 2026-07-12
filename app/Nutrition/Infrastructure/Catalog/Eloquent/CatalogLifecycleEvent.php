<?php

namespace App\Nutrition\Infrastructure\Catalog\Eloquent;

use App\Models\User;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleOperation;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleOutcome;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleReason;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleState;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleSubjectType;
use Database\Factories\Nutrition\Catalog\CatalogLifecycleEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'public_id',
    'subject_type',
    'subject_id',
    'subject_public_id',
    'event_type',
    'outcome',
    'reason_code',
    'reason',
    'previous_state',
    'next_state',
    'eligibility_reasons',
    'actor_user_id',
    'actor_reference',
    'metadata',
    'occurred_at',
    'idempotency_key',
    'command_fingerprint',
    'correlation_id',
    'transaction_id',
])]
#[Table(name: 'catalog_lifecycle_events', dateFormat: 'Y-m-d H:i:s.uP')]
#[UseFactory(CatalogLifecycleEventFactory::class)]
class CatalogLifecycleEvent extends Model
{
    /** @use HasFactory<CatalogLifecycleEventFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'subject_type' => CatalogLifecycleSubjectType::class,
            'subject_id' => 'integer',
            'event_type' => CatalogLifecycleOperation::class,
            'outcome' => CatalogLifecycleOutcome::class,
            'reason_code' => CatalogLifecycleReason::class,
            'previous_state' => CatalogLifecycleState::class,
            'next_state' => CatalogLifecycleState::class,
            'eligibility_reasons' => 'array',
            'actor_user_id' => 'integer',
            'metadata' => 'array',
            'occurred_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
