<?php

namespace App\Tests\Core\Parser;

use App\Core\Parser\ExpressionDqlParser;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Exception\ValidatorException;

final class ExpressionDqlParserTest extends TestCase
{
    public function testCompileSimpleExpressionCreatesWhereAndParameters(): void
    {
        $parser = new ExpressionDqlParser();
        $parser->setDataClass('App\\Common\\Entity\\Content');
        $parser->setValues(['entity' => '']);
        $parser->setExpression('entity.getId() == 1');
        $parser->compile();

        self::assertNotSame('', $parser->getWhere());
        self::assertCount(1, $parser->getParameters());
    }

    public function testCompileEmptyExpressionThrows(): void
    {
        $parser = new ExpressionDqlParser();
        $parser->setDataClass('App\\Common\\Entity\\Content');

        $this->expectException(ValidatorException::class);
        $parser->setExpression('')->compile();
    }

    public function testTopLevelAttributeAddsIsNotNull(): void
    {
        $parser = new ExpressionDqlParser();
        $parser->setDataClass('App\\Common\\Entity\\Content');
        $parser->setValues(['entity' => '']);
        $parser->setExpression('entity.getOwner().getId()');
        $parser->compile();

        self::assertStringContainsString('IS NOT NULL', $parser->getWhere());
    }
}
