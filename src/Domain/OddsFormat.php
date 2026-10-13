<?php

declare(strict_types=1);

namespace BestOddsTable\Domain;

/**
 * The three display formats the plugin supports.
 */
enum OddsFormat: string
{
    case Decimal = 'decimal';
    case Fractional = 'fractional';
    case American = 'american';

    /**
     * Lenient lookup for user input (settings, block attributes, query args).
     */
    public static function fromInput(?string $value, self $default = self::Decimal): self
    {
        return self::tryFrom(strtolower(trim((string) $value))) ?? $default;
    }
}
