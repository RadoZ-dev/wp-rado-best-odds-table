<?php

declare(strict_types=1);

namespace BestOddsTable\Tests\Unit;

use InvalidArgumentException;
use BestOddsTable\Conversion\OddsConverter;
use BestOddsTable\Domain\OddsFormat;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OddsConverterTest extends TestCase
{
    private OddsConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new OddsConverter();
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function fractionalCases(): iterable
    {
        // fractional => [decimal, american]
        yield 'odds against' => ['5/2', '3.50', '+250'];
        yield 'odds on' => ['1/2', '1.50', '-200'];
        yield 'evens' => ['1/1', '2.00', '+100'];
        yield 'ten elevenths' => ['10/11', '1.91', '-110'];
        yield 'long shot' => ['14/1', '15.00', '+1400'];
        yield 'awkward fraction' => ['85/40', '3.13', '+213'];
    }

    #[DataProvider('fractionalCases')]
    public function testFractionalConvertsToDecimalAndAmerican(string $fractional, string $decimal, string $american): void
    {
        $odds = $this->converter->parse($fractional, OddsFormat::Fractional);

        self::assertSame($decimal, $this->converter->format($odds, OddsFormat::Decimal));
        self::assertSame($american, $this->converter->format($odds, OddsFormat::American));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function americanToFractionalCases(): iterable
    {
        yield 'positive (conventional 6/4)' => ['+150', '6/4'];
        yield 'negative' => ['-125', '4/5'];
        yield 'minus 110' => ['-110', '10/11'];
        yield 'no sign' => ['200', '2/1'];
        yield 'plus 100' => ['+100', '1/1'];
    }

    #[DataProvider('americanToFractionalCases')]
    public function testAmericanConvertsToFractional(string $american, string $fractional): void
    {
        self::assertSame(
            $fractional,
            $this->converter->convert($american, OddsFormat::American, OddsFormat::Fractional)
        );
    }

    public function testDecimalToFractionalUsesReadableFractions(): void
    {
        self::assertSame('10/11', $this->converter->convert('1.9091', OddsFormat::Decimal, OddsFormat::Fractional));
        self::assertSame('1/3', $this->converter->convert('1.3333', OddsFormat::Decimal, OddsFormat::Fractional));
        self::assertSame('7/4', $this->converter->convert('2.75', OddsFormat::Decimal, OddsFormat::Fractional));
    }

    public function testEvensAliases(): void
    {
        foreach (['evs', 'Evens', ' EVS '] as $alias) {
            $odds = $this->converter->parse($alias, OddsFormat::Fractional);
            self::assertSame(2.0, $odds->decimal());
        }
    }

    public function testRoundTripIsStable(): void
    {
        foreach (['1/5', '4/6', '6/4', '9/4', '11/2', '33/1'] as $fractional) {
            $american = $this->converter->convert($fractional, OddsFormat::Fractional, OddsFormat::American);
            $back = $this->converter->convert($american, OddsFormat::American, OddsFormat::Fractional);

            self::assertSame($fractional, $back, "Round trip via {$american}");
        }
    }

    /**
     * @return iterable<string, array{string, OddsFormat}>
     */
    public static function invalidInputs(): iterable
    {
        yield 'decimal below one' => ['0.9', OddsFormat::Decimal];
        yield 'decimal exactly one' => ['1.0', OddsFormat::Decimal];
        yield 'decimal text' => ['abc', OddsFormat::Decimal];
        yield 'fraction zero denominator' => ['5/0', OddsFormat::Fractional];
        yield 'fraction zero numerator' => ['0/5', OddsFormat::Fractional];
        yield 'fraction garbage' => ['five to two', OddsFormat::Fractional];
        yield 'american inside dead zone' => ['+50', OddsFormat::American];
        yield 'american negative dead zone' => ['-99', OddsFormat::American];
        yield 'american garbage' => ['+1x0', OddsFormat::American];
    }

    #[DataProvider('invalidInputs')]
    public function testRejectsInvalidInput(string $raw, OddsFormat $format): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->converter->parse($raw, $format);
    }
}
