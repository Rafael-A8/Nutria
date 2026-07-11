<?php

namespace App\Nutrition\Domain\Drafts;

final readonly class InterpretationDraft
{
    public function __construct(
        public string $rawMessage,
        public string $source,
        public MealDraft $meal,
        public ?string $clarificationQuestion = null,
        public ?string $clarificationReason = null,
    ) {}
}
