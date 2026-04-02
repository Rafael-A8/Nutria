<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['meal_id', 'description', 'quantity_grams', 'calories'])]
class MealItem extends Model
{
    public function meal(): BelongsTo
    {
        return $this->belongsTo(Meal::class);
    }
}
