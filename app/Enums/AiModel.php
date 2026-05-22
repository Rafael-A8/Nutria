<?php

namespace App\Enums;

use Laravel\Ai\Enums\Lab;

enum AiModel: string
{
    case GeminiPro = 'gemini-3.1-pro-preview';
    case GptFourO = 'gpt-4o';

    /** Default model used when the user has no preference set. */
    public static function default(): self
    {
        return self::GeminiPro;
    }

    /** Resolve the Lab provider for this model. */
    public function provider(): Lab
    {
        return match ($this) {
            self::GeminiPro => Lab::Gemini,
            self::GptFourO => Lab::OpenAI,
        };
    }

    /**
     * Returns the provider chain for automatic failover support.
     * Providers are tried in order; next is used on RateLimit/Overload.
     *
     * @return array<string, string>
     */
    public function providerChain(): array
    {
        return match ($this) {
            self::GeminiPro => [
                Lab::Gemini->value => self::GeminiPro->value,
                Lab::OpenAI->value => self::GptFourO->value,
            ],
            self::GptFourO => [
                Lab::OpenAI->value => self::GptFourO->value,
            ],
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::GeminiPro => 'Recomendado',
            self::GptFourO => 'Alternativo',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::GeminiPro => 'Gemini 3.1 Pro Preview — melhor em raciocínio',
            self::GptFourO => 'GPT-4o — mais poderoso',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::GeminiPro => 'brain',
            self::GptFourO => 'zap',
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
