<?php

namespace App\Enums;

use Laravel\Ai\Enums\Lab;

enum AiModel: string
{
    case GeminiFlashLite = 'gemini-2.0-flash-lite';
    case GptFourOMini = 'gpt-4o-mini';

    /** Default model used when the user has no preference set. */
    public static function default(): self
    {
        return self::GeminiFlashLite;
    }

    /** Resolve the Lab provider for this model. */
    public function provider(): Lab
    {
        return match ($this) {
            self::GeminiFlashLite => Lab::Gemini,
            self::GptFourOMini => Lab::OpenAI,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::GeminiFlashLite => 'Recomendado',
            self::GptFourOMini => 'Alternativo',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::GeminiFlashLite => 'Gemini 2.0 Flash Lite — melhor em cálculos e mais barato',
            self::GptFourOMini => 'GPT-4o Mini — estilo de resposta diferente',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::GeminiFlashLite => 'brain',
            self::GptFourOMini => 'zap',
        };
    }

    /**
     * Format for the frontend options list.
     *
     * @return array{value: string, label: string, description: string, icon: string}
     */
    public function toOption(): array
    {
        return [
            'value' => $this->value,
            'label' => $this->label(),
            'description' => $this->description(),
            'icon' => $this->icon(),
        ];
    }

    /**
     * All models as frontend option list.
     *
     * @return list<array{value: string, label: string, description: string, icon: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $model) => $model->toOption(), self::cases());
    }
}
