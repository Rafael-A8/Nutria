<?php

namespace App\Nutrition\Application\Catalog\Lifecycle\Contracts;

use App\Nutrition\Application\Catalog\Lifecycle\ValueObjects\CatalogLifecycleEventDraft;
use App\Nutrition\Application\Catalog\Lifecycle\ValueObjects\CatalogLifecycleStoredEvent;

interface CatalogLifecycleEventStore
{
    public function findRootByIdempotencyKey(string $idempotencyKey): ?CatalogLifecycleStoredEvent;

    public function storeRoot(CatalogLifecycleEventDraft $event): CatalogLifecycleStoredEvent;

    public function appendDerived(CatalogLifecycleEventDraft $event): CatalogLifecycleStoredEvent;
}
