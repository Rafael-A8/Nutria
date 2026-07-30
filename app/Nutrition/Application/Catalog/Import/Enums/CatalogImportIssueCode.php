<?php

namespace App\Nutrition\Application\Catalog\Import\Enums;

enum CatalogImportIssueCode: string
{
    case StructuralShapeInvalid = 'structural_shape_invalid';
    case ProvenanceMissing = 'provenance_missing';
    case SourceDeclarationMissing = 'source_declaration_missing';
    case SourceUntrusted = 'source_untrusted';
    case NutritionalShapeInvalid = 'nutritional_shape_invalid';
    case DefaultCaloriesAssumption = 'default_calories_assumption';
    case ApplicationEstimate = 'application_estimate';
    case NormalizationCollision = 'normalization_collision';
    case DuplicateAlias = 'duplicate_alias';
    case ConceptualIdentityUnresolved = 'conceptual_identity_unresolved';
    case GenericityUnresolved = 'genericity_unresolved';
    case ClassificationUnresolved = 'classification_unresolved';
    case PreparationUnresolved = 'preparation_unresolved';
    case AliasKindUnresolved = 'alias_kind_unresolved';
    case ImmutableFieldConflict = 'immutable_field_conflict';
}
