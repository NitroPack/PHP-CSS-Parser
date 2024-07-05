<?php

namespace Sabberworm\CSS\Tests\Value;

use PHPUnit\Framework\TestCase;
use Sabberworm\CSS\Value\CalcRuleValueList;
use Sabberworm\CSS\Value\RuleValueList;

/**
 * @covers \Sabberworm\CSS\Value\CalcRuleValueList
 */
final class CalcRuleValueListTest extends TestCase
{
    /**
     * @test
     */
    public function isRuleValueList(): void
    {
        $subject = new CalcRuleValueList();

        self::assertInstanceOf(RuleValueList::class, $subject);
    }

    /**
     * @test
     */
    public function getLineNumberByDefaultReturnsZero(): void
    {
        $subject = new CalcRuleValueList();

        self::assertSame(0, $subject->getLineNo());
    }

    /**
     * @test
     */
    public function getLineNoReturnsLineNumberProvidedToConstructor(): void
    {
        $lineNumber = 42;

        $subject = new CalcRuleValueList($lineNumber);

        self::assertSame($lineNumber, $subject->getLineNo());
    }

    /**
     * @test
     */
    public function separatorAlwaysIsComma(): void
    {
        $subject = new CalcRuleValueList();

        self::assertSame(',', $subject->getListSeparator());
    }
}
