<?php

namespace App\Enums;

enum ConversationSummaryTriggerType: string
{
    case Weekly = 'weekly';
    case Biweekly = 'biweekly';
    case Monthly = 'monthly';
    case MessageLimit = 'message_limit';
    case TokenLimit = 'token_limit';
    case Manual = 'manual';
    case BillingLimit = 'billing_limit';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
