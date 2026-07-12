<?php

namespace App\Nutrition\Domain\Catalog\Lifecycle\Contracts;

use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleState;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleSubjectType;

interface CatalogLifecycleSnapshot
{
    public function subjectType(): CatalogLifecycleSubjectType;

    public function subjectId(): string;

    public function exists(): bool;

    public function state(): ?CatalogLifecycleState;
}
