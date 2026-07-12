<?php

namespace App\Nutrition\Domain\Catalog\Enums;

enum CatalogReviewStatus: string
{
    case Draft = 'draft';
    case PendingReview = 'pending_review';
    case Approved = 'approved';
    case Rejected = 'rejected';
}
