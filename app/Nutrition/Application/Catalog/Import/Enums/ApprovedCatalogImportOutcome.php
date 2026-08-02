<?php

namespace App\Nutrition\Application\Catalog\Import\Enums;

enum ApprovedCatalogImportOutcome: string
{
    case Applied = 'applied';
    case NoOpReplay = 'no_op_replay';
    case ArtifactInvalid = 'artifact_invalid';
    case SourceDrift = 'source_drift';
    case ActorInvalid = 'actor_invalid';
    case CatalogDrift = 'catalog_drift';
    case CatalogConflict = 'catalog_conflict';
    case PersistenceFailed = 'persistence_failed';
    case PostWriteVerificationFailed = 'post_write_verification_failed';
}
