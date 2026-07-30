<?php

namespace App\Nutrition\Application\Catalog\Import;

use App\Nutrition\Application\Catalog\Import\ValueObjects\CatalogImportSemanticGraph;
use JsonException;

final class CatalogImportSemanticGraphFingerprint
{
    /** @throws JsonException */
    public static function forGraph(CatalogImportSemanticGraph $graph): string
    {
        return hash(
            'sha256',
            CanonicalCatalogImportJson::serializeSemanticGraph($graph->toCanonicalPayload()),
        );
    }
}
