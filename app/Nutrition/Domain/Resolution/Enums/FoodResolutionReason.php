<?php

namespace App\Nutrition\Domain\Resolution\Enums;

enum FoodResolutionReason: string
{
    case BlankFoodText = 'blank_food_text';
    case NoExactMatch = 'no_exact_match';
    case AliasCollision = 'alias_collision';
    case CanonicalAliasCollision = 'canonical_alias_collision';
    case GenericAliasRequiresClarification = 'generic_alias_requires_clarification';
    case ExplicitGenericReference = 'explicit_generic_reference';
    case RoleIncompatible = 'role_incompatible';
    case PreparationIncompatible = 'preparation_incompatible';
    case MultipleCompatibleCandidates = 'multiple_compatible_candidates';
    case InactiveReference = 'inactive_reference';
    case NoActiveReviewedVersion = 'no_active_reviewed_version';
    case PrivateScopeExcluded = 'private_scope_excluded';
    case UniqueCompatibleExactMatch = 'unique_compatible_exact_match';
}
