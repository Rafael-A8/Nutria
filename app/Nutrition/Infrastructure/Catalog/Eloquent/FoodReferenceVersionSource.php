<?php

namespace App\Nutrition\Infrastructure\Catalog\Eloquent;

use App\Models\User;
use App\Nutrition\Domain\Catalog\Enums\FoodReferenceVersionSourceRole;
use Database\Factories\Nutrition\Catalog\FoodReferenceVersionSourceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'food_reference_version_id',
    'food_source_id',
    'role',
    'source_record_key',
    'evidence_metadata',
    'created_by_user_id',
])]
#[Table(dateFormat: 'Y-m-d H:i:s.uP')]
#[UseFactory(FoodReferenceVersionSourceFactory::class)]
class FoodReferenceVersionSource extends Model
{
    /** @use HasFactory<FoodReferenceVersionSourceFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => FoodReferenceVersionSourceRole::class,
            'evidence_metadata' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(FoodReferenceVersion::class, 'food_reference_version_id');
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(FoodSource::class, 'food_source_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
