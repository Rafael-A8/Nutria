<?php

namespace App\Nutrition\Application\Catalog\Lifecycle;

use App\Nutrition\Domain\Catalog\Enums\CatalogReviewStatus;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleState;
use App\Nutrition\Infrastructure\Catalog\Eloquent\FoodAlias;
use App\Nutrition\Infrastructure\Catalog\Eloquent\FoodPortion;
use App\Nutrition\Infrastructure\Catalog\Eloquent\FoodReference;
use App\Nutrition\Infrastructure\Catalog\Eloquent\FoodReferenceVersion;
use App\Nutrition\Infrastructure\Catalog\Eloquent\FoodSource;
use InvalidArgumentException;

final class CatalogLifecycleProjectionStateResolver
{
    public function reviewable(FoodReferenceVersion|FoodAlias|FoodPortion $subject): CatalogLifecycleState
    {
        $reviewStatus = $subject->review_status;

        if (! $reviewStatus instanceof CatalogReviewStatus) {
            throw new InvalidArgumentException('The catalog review projection is structurally invalid.');
        }

        if ($subject->archived_at !== null) {
            return CatalogLifecycleState::Archived;
        }

        if ($subject->withdrawn_at !== null) {
            return CatalogLifecycleState::Withdrawn;
        }

        $isActive = $subject->activated_at !== null
            && $subject->deactivated_at === null
            && $subject->withdrawn_at === null
            && $subject->archived_at === null;

        if ($isActive) {
            $this->assertPublishedAndApproved($subject, 'active');

            return CatalogLifecycleState::Active;
        }

        if ($subject->deactivated_at !== null) {
            if ($subject->activated_at === null) {
                throw new InvalidArgumentException('The catalog deactivation projection is structurally invalid.');
            }

            $this->assertPublishedAndApproved($subject, 'deactivated');

            return CatalogLifecycleState::Deactivated;
        }

        if ($subject->published_at !== null) {
            $this->assertPublishedAndApproved($subject, 'published');

            return CatalogLifecycleState::PublishedInactive;
        }

        if ($subject->activated_at !== null) {
            throw new InvalidArgumentException('The catalog activation projection is structurally invalid.');
        }

        return match ($reviewStatus) {
            CatalogReviewStatus::Rejected => CatalogLifecycleState::Rejected,
            CatalogReviewStatus::Approved => CatalogLifecycleState::Approved,
            CatalogReviewStatus::PendingReview => CatalogLifecycleState::PendingReview,
            CatalogReviewStatus::Draft => CatalogLifecycleState::Draft,
        };
    }

    public function source(FoodSource $source): CatalogLifecycleState
    {
        return $source->archived_at === null
            ? CatalogLifecycleState::Available
            : CatalogLifecycleState::Archived;
    }

    public function reference(FoodReference $reference): CatalogLifecycleState
    {
        return $reference->archived_at === null
            ? CatalogLifecycleState::Available
            : CatalogLifecycleState::Archived;
    }

    private function assertPublishedAndApproved(
        FoodReferenceVersion|FoodAlias|FoodPortion $subject,
        string $state,
    ): void {
        if ($subject->published_at === null || $subject->review_status !== CatalogReviewStatus::Approved) {
            throw new InvalidArgumentException("The catalog {$state} projection is structurally invalid.");
        }
    }
}
