<?php

declare(strict_types=1);

namespace BestOddsTable\Conversion;

use InvalidArgumentException;
use BestOddsTable\Domain\Odds;
use BestOddsTable\Domain\OddsFormat;

/**
 * Parses odds from any supported format and formats them back.
 *
 * Formulas (d = decimal odds):
 *  - Fractional a/b  -> d = a/b + 1
 *  - American +x     -> d = x/100 + 1
 *  - American -x     -> d = 100/x + 1
 *  - d >= 2          -> American +((d - 1) * 100)
 *  - d <  2          -> American -(100 / (d - 1))
 *
 * Decimal to fractional uses a continued-fraction approximation with a
 * bounded denominator, so 1.9091 becomes 10/11 rather than 9091/10000.
 */
final class OddsConverter
{
    /**
     * UK bookmakers quote a few prices unreduced (6/4, not 3/2). Mathematically
     * equal, but readers expect the conventional form.
     */
    private const CONVENTIONAL_FRACTIONS = [
        '3/2' => '6/4',
        '2/3' => '4/6',
    ];

    public function __construct(private readonly int $maxDenominator = 100)
    {
    }

    public function parse(string $raw, OddsFormat $format): Odds
    {
        $value = trim($raw);

        return match ($format) {
            OddsFormat::Decimal => $this->parseDecimal($value),
            OddsFormat::Fractional => $this->parseFractional($value),
            OddsFormat::American => $this->parseAmerican($value),
        };
    }

    public function format(Odds $odds, OddsFormat $format): string
    {
        return match ($format) {
            OddsFormat::Decimal => number_format($odds->decimal(), 2, '.', ''),
            OddsFormat::Fractional => $this->toFractional($odds->decimal()),
            OddsFormat::American => $this->toAmerican($odds->decimal()),
        };
    }

    /**
     * Convenience for the block/REST layer: convert a raw value between formats.
     */
    public function convert(string $raw, OddsFormat $from, OddsFormat $to): string
    {
        return $this->format($this->parse($raw, $from), $to);
    }

    private function parseDecimal(string $value): Odds
    {
        if (!is_numeric($value)) {
            throw new InvalidArgumentException(sprintf('"%s" is not valid decimal odds.', $value));
        }

        return Odds::fromDecimal((float) $value);
    }

    private function parseFractional(string $value): Odds
    {
        if (in_array(strtolower($value), ['evs', 'evens', 'even'], true)) {
            return Odds::fromDecimal(2.0);
        }

        if (!preg_match('~^(\d+)\s*/\s*(\d+)$~', $value, $m) || (int) $m[2] === 0 || (int) $m[1] === 0) {
            throw new InvalidArgumentException(sprintf('"%s" is not valid fractional odds.', $value));
        }

        return Odds::fromDecimal((int) $m[1] / (int) $m[2] + 1);
    }

    private function parseAmerican(string $value): Odds
    {
        if (!preg_match('~^[+-]?\d+(\.\d+)?$~', $value)) {
            throw new InvalidArgumentException(sprintf('"%s" is not valid American odds.', $value));
        }

        $number = (float) $value;

        // American odds between -100 and +100 (exclusive) do not exist.
        if (abs($number) < 100) {
            throw new InvalidArgumentException(sprintf('"%s" is not valid American odds.', $value));
        }

        $decimal = $number > 0 ? $number / 100 + 1 : 100 / abs($number) + 1;

        return Odds::fromDecimal($decimal);
    }

    private function toAmerican(float $decimal): string
    {
        if ($decimal >= 2.0) {
            return '+' . (string) (int) round(($decimal - 1) * 100);
        }

        return '-' . (string) (int) round(100 / ($decimal - 1));
    }

    private function toFractional(float $decimal): string
    {
        [$numerator, $denominator] = $this->approximateFraction($decimal - 1);
        $fraction = $numerator . '/' . $denominator;

        return self::CONVENTIONAL_FRACTIONS[$fraction] ?? $fraction;
    }

    /**
     * Best rational approximation of $x with denominator <= maxDenominator.
     *
     * @return array{0: int, 1: int}
     */
    private function approximateFraction(float $x): array
    {
        $whole = (int) floor($x);
        $remainder = $x - $whole;

        // Convergents h/k of the continued fraction expansion.
        [$hPrev, $h] = [1, $whole];
        [$kPrev, $k] = [0, 1];

        while ($remainder > 1e-9) {
            $reciprocal = 1 / $remainder;
            $term = (int) floor($reciprocal);
            $remainder = $reciprocal - $term;

            $hNext = $term * $h + $hPrev;
            $kNext = $term * $k + $kPrev;

            if ($kNext > $this->maxDenominator) {
                break;
            }

            [$hPrev, $h] = [$h, $hNext];
            [$kPrev, $k] = [$k, $kNext];
        }

        return [$h, $k];
    }
}
