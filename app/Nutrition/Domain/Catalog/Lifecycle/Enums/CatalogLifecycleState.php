<?php

namespace App\Nutrition\Domain\Catalog\Lifecycle\Enums;

enum CatalogLifecycleState: string
{
    case Available = 'available';
    case Draft = 'draft';
    case PendingReview = 'pending_review';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case PublishedInactive = 'published_inactive';
    case Active = 'active';
    case Deactivated = 'deactivated';
    case Withdrawn = 'withdrawn';
    case Archived = 'archived';
}
