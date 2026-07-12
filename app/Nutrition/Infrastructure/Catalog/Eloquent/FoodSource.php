<?php

namespace App\Nutrition\Infrastructure\Catalog\Eloquent;

use App\Models\User;
use App\Nutrition\Domain\Catalog\Enums\CatalogVisibility;
use App\Nutrition\Domain\Catalog\Enums\FoodSourceAuthorityStatus;
use App\Nutrition\Domain\Catalog\Enums\FoodSourceKind;
use Database\Factories\Nutrition\Catalog\FoodSourceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'public_id',
    'visibility',
    'owner_user_id',
    'kind',
    'authority_status',
    'title',
    'publisher',
    'edition',
    'source_uri',
    'citation',
    'license',
    'checksum_algorithm',
    'checksum',
    'retrieved_at',
    'metadata',
    'archived_at',
    'archived_by_user_id',
    'archive_reason',
    'created_by_user_id',
])]
#[Table(dateFormat: 'Y-m-d H:i:s.uP')]
#[UseFactory(FoodSourceFactory::class)]
class FoodSource extends Model
{
    /** @use HasFactory<FoodSourceFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'visibility' => CatalogVisibility::class,
            'kind' => FoodSourceKind::class,
            'authority_status' => FoodSourceAuthorityStatus::class,
            'retrieved_at' => 'immutable_datetime',
            'metadata' => 'array',
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

    public function versionSourceLinks(): HasMany
    {
        return $this->hasMany(FoodReferenceVersionSource::class, 'food_source_id');
    }

    public function aliases(): HasMany
    {
        return $this->hasMany(FoodAlias::class, 'food_source_id');
    }

    public function portions(): HasMany
    {
        return $this->hasMany(FoodPortion::class, 'food_source_id');
    }
}
