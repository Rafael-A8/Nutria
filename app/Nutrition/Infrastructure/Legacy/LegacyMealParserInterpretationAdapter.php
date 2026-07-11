<?php

namespace App\Nutrition\Infrastructure\Legacy;

use App\Nutrition\Domain\Drafts\InterpretationDraft;
use App\Nutrition\Domain\Drafts\MealComponentDraft;
use App\Nutrition\Domain\Drafts\MealDraft;
use App\Nutrition\Domain\Drafts\MealEntryDraft;
use App\Nutrition\Domain\Enums\ComponentRole;
use App\Nutrition\Domain\Enums\QuantitySource;
use App\Nutrition\Domain\Enums\QuantityType;
use App\Nutrition\Domain\ValueObjects\Quantity;
use DateTimeImmutable;
use DateTimeZone;
use ValueError;

final class LegacyMealParserInterpretationAdapter
{
    public function __construct(private readonly DateTimeZone $timezone) {}

    /**
     * @param  array<string, mixed>  $parserResult
     */
    public function fromParserResult(
        string $rawMessage,
        array $parserResult,
        string $source = 'legacy_parser',
    ): InterpretationDraft {
        return new InterpretationDraft(
            rawMessage: $rawMessage,
            source: $source,
            meal: new MealDraft(
                mealType: $this->nonEmptyString($parserResult['meal_type'] ?? null),
                consumedAt: $this->consumedAt($parserResult['consumed_at'] ?? null),
                entries: [new MealEntryDraft(
                    originalText: $rawMessage,
                    quantity: $this->entryQuantity($parserResult['meal_total_quantity_grams'] ?? null),
                    components: $this->components($parserResult['items'] ?? null),
                )],
            ),
            clarificationQuestion: $this->nonEmptyString($parserResult['clarification_question'] ?? null),
            clarificationReason: $this->nonEmptyString($parserResult['clarification_reason'] ?? null),
        );
    }

    private function consumedAt(mixed $value): ?DateTimeImmutable
    {
        if ($this->nonEmptyString($value) === null || ! is_string($value)) {
            return null;
        }

        try {
            $consumedAt = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, $this->timezone);
        } catch (ValueError) {
            return null;
        }

        $errors = DateTimeImmutable::getLastErrors();

        if (
            $consumedAt === false
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
        ) {
            return null;
        }

        return $consumedAt;
    }

    private function entryQuantity(mixed $value): ?Quantity
    {
        $amount = $this->positiveNumber($value);

        if ($amount === null) {
            return null;
        }

        return new Quantity(
            type: QuantityType::EntryTotal,
            source: QuantitySource::UserReported,
            amount: $amount,
            unit: 'g',
        );
    }

    /**
     * @return list<MealComponentDraft>
     */
    private function components(mixed $items): array
    {
        if (! is_array($items)) {
            return [];
        }

        $components = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $description = $this->nonEmptyString($item['description'] ?? null);

            if ($description === null) {
                continue;
            }

            $components[] = new MealComponentDraft(
                originalText: $description,
                interpretedFoodText: $description,
                role: ComponentRole::Unknown,
                quantity: $this->componentQuantity($item),
            );
        }

        return $components;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function componentQuantity(array $item): ?Quantity
    {
        $grams = $this->positiveNumber($item['quantity_grams'] ?? null);

        if ($grams !== null) {
            return new Quantity(
                type: QuantityType::Exact,
                source: QuantitySource::UserReported,
                amount: $grams,
                unit: 'g',
                gramAmount: $grams,
            );
        }

        $quantityText = $this->nonEmptyString($item['quantity_text'] ?? null);

        if ($quantityText === null) {
            return null;
        }

        return new Quantity(
            type: QuantityType::Vague,
            source: QuantitySource::UserReported,
            measureText: $quantityText,
        );
    }

    private function positiveNumber(mixed $value): ?float
    {
        if ((! is_int($value) && ! is_float($value)) || ! is_finite((float) $value) || $value <= 0) {
            return null;
        }

        return (float) $value;
    }

    private function nonEmptyString(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return $value;
    }
}
