<?php

namespace App\Enums;

enum ConversationSummaryType: string
{
    case ConversationCycle = 'conversation_cycle';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
