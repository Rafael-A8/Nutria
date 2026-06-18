<?php

namespace App\Enums;

use Laravel\Ai\Enums\Lab;

enum AiModel: string
{
    case Gemini = 'gemini';
    case OpenAI = 'openai';

    /** Get the actual SDK model string for this enum case. */
    public function sdkModel(): string
    {
        return match ($this) {
            self::Gemini => 'gemini-3.5-flash',
            self::OpenAI => 'gpt-4o',
        };
    }

    /** Default model used when the user has no preference set. */
    public static function default(): self
    {
        return self::OpenAI;
    }

    /** Resolve the Lab provider for this model. */
    public function provider(): Lab
    {
        return match ($this) {
            self::Gemini => Lab::Gemini,
            self::OpenAI => Lab::OpenAI,
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
            self::Gemini => [
                Lab::Gemini->value => self::Gemini->sdkModel(),
                Lab::OpenAI->value => self::OpenAI->sdkModel(),
            ],
            self::OpenAI => [
                Lab::OpenAI->value => self::OpenAI->sdkModel(),
            ],
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Gemini => 'Gemini 3.5 Flash',
            self::OpenAI => 'OpenAI',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Gemini => 'Teste — Gemini 3.5 Flash com thinking medium.',
            self::OpenAI => 'Alternativo — modelo treinado pela OpenAI.',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Gemini => 'brain',
            self::OpenAI => 'zap',
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
