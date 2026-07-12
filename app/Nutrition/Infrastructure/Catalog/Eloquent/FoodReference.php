<?php

namespace App\Nutrition\Infrastructure\Catalog\Eloquent;

use App\Models\User;
use App\Nutrition\Domain\Catalog\Enums\CatalogVisibility;
use Database\Factories\Nutrition\Catalog\FoodReferenceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'public_id',
    'stable_key',
    'visibility',
    'owner_user_id',
    'is_generic',
    'archived_at',
    'archived_by_user_id',
    'archive_reason',
    'created_by_user_id',
])]
#[Table(dateFormat: 'Y-m-d H:i:s.uP')]
#[UseFactory(FoodReferenceFactory::class)]
class FoodReference extends Model
{
    /** @use HasFactory<FoodReferenceFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'visibility' => CatalogVisibility::class,
            'is_generic' => 'boolean',
            'archived_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function archivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'archived_by_user_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(FoodReferenceVersion::class, 'food_reference_id');
    }

    public function aliases(): HasMany
    {
        return $this->hasMany(FoodAlias::class, 'food_reference_id');
    }

    public function portions(): HasMany
    {
        return $this->hasMany(FoodPortion::class, 'food_reference_id');
    }
}
