<?php

namespace App\Enums;

enum UserMemoryCategory: string
{
    case Restricoes = 'restricoes';
    case Preferencias = 'preferencias';
    case Comportamento = 'comportamento';
    case Objetivos = 'objetivos';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * @return list<string>
     */
    public static function priorityValues(): array
    {
        return [
            self::Restricoes->value,
            self::Objetivos->value,
        ];
    }

    /**
     * @return list<string>
     */
    public static function contextualValues(): array
    {
        return [
            self::Preferencias->value,
            self::Comportamento->value,
        ];
    }

    public static function schemaDescription(): string
    {
        return 'Category. Must be exactly one of: '.implode(', ', self::values()).'.';
    }
}
