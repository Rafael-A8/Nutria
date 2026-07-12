<?php

namespace App\Nutrition\Infrastructure\Catalog\Eloquent;

use App\Models\User;
use App\Nutrition\Domain\Catalog\Enums\CatalogReviewStatus;
use Database\Factories\Nutrition\Catalog\FoodAliasFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'public_id',
    'lineage_id',
    'food_reference_id',
    'revision_number',
    'supersedes_food_alias_id',
    'display_alias',
    'normalized_alias',
    'locale',
    'alias_kind',
    'food_source_id',
    'source_record_key',
    'provenance',
    'review_status',
    'submitted_at',
    'submitted_by_user_id',
    'reviewed_at',
    'reviewed_by_user_id',
    'review_reason',
    'published_at',
    'published_by_user_id',
    'activated_at',
    'activated_by_user_id',
    'deactivated_at',
    'deactivated_by_user_id',
    'deactivation_reason',
    'withdrawn_at',
    'withdrawn_by_user_id',
    'withdrawal_reason',
    'archived_at',
    'archived_by_user_id',
    'archive_reason',
    'created_by_user_id',
])]
#[Table(dateFormat: 'Y-m-d H:i:s.uP')]
#[UseFactory(FoodAliasFactory::class)]
class FoodAlias extends Model
{
    /** @use HasFactory<FoodAliasFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'revision_number' => 'integer',
            'provenance' => 'array',
            'review_status' => CatalogReviewStatus::class,
            'submitted_at' => 'immutable_datetime',
            'reviewed_at' => 'immutable_datetime',
            'published_at' => 'immutable_datetime',
            'activated_at' => 'immutable_datetime',
            'deactivated_at' => 'immutable_datetime',
            'withdrawn_at' => 'immutable_datetime',
            'archived_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function reference(): BelongsTo
    {
        return $this->belongsTo(FoodReference::class, 'food_reference_id');
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(FoodSource::class, 'food_source_id');
    }

    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_food_alias_id');
    }

    public function supersededBy(): HasOne
    {
        return $this->hasOne(self::class, 'supersedes_food_alias_id');
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by_user_id');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    public function publishedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by_user_id');
    }

    public function activatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'activated_by_user_id');
    }

    public function deactivatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deactivated_by_user_id');
    }

    public function withdrawnBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'withdrawn_by_user_id');
    }

    public function archivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'archived_by_user_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
