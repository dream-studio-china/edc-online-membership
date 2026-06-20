<?php

declare(strict_types=1);

namespace App\Tests\Core\Parser;

use App\Core\Parser\ExpressionDqlParser;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Exception\ValidatorException;

final class ExpressionDqlParserFullTest extends TestCase
{
    public function testCompileWithNotEqual(): void
    {
        $parser = new ExpressionDqlParser();
        $parser->setDataClass('App\Common\Entity\Content');
        $parser->setValues(['entity' => '']);
        $parser->setExpression('entity.getId() != 1');
        $parser->compile();

        self::assertStringContainsString('!=', $parser->getWhere());
    }

    public function testCompileWithGreaterThan(): void
    {
        $parser = new ExpressionDqlParser();
        $parser->setDataClass('App\Common\Entity\Content');
        $parser->setValues(['entity' => '']);
        $parser->setExpression('entity.getId() > 10');
        $parser->compile();

        $where = $parser->getWhere();
        self::assertNotSame('', $where);
    }

    public function testCompileWithLessThanOrEqual(): void
    {
        $parser = new ExpressionDqlParser();
        $parser->setDataClass('App\Common\Entity\Content');
        $parser->setValues(['entity' => '']);
        $parser->setExpression('entity.getId() <= 100');
        $parser->compile();

        $where = $parser->getWhere();
        self::assertNotSame('', $where);
    }

    public function testCompileWithLogicalAnd(): void
    {
        $parser = new ExpressionDqlParser();
        $parser->setDataClass('App\Common\Entity\Content');
        $parser->setValues(['entity' => '']);
        $parser->setExpression('entity.getId() > 0 && entity.getId() < 100');
        $parser->compile();

        self::assertStringContainsString('AND', $parser->getWhere());
    }

    public function testCompileWithLogicalOr(): void
    {
        $parser = new ExpressionDqlParser();
        $parser->setDataClass('App\Common\Entity\Content');
        $parser->setValues(['entity' => '']);
        $parser->setExpression('entity.getId() == 1 || entity.getId() == 2');
        $parser->compile();

        self::assertStringContainsString('OR', $parser->getWhere());
    }

    public function testCompileWithAdditionOperator(): void
    {
        $parser = new ExpressionDqlParser();
        $parser->setDataClass('App\Common\Entity\Content');
        $parser->setValues(['entity' => '']);
        $parser->setExpression('entity.getId() + 1 == 5');
        $parser->compile();

        self::assertCount(2, $parser->getParameters());
    }

    public function testCompileWithStringComparison(): void
    {
        $parser = new ExpressionDqlParser();
        $parser->setDataClass('App\Common\Entity\Content');
        $parser->setValues(['entity' => '']);
        $parser->setExpression('entity.getTitle() == "hello"');
        $parser->compile();

        self::assertCount(1, $parser->getParameters());
    }

    public function testCompileWithNestedRelation(): void
    {
        $parser = new ExpressionDqlParser();
        $parser->setDataClass('App\Common\Entity\Content');
        $parser->setValues(['entity' => '']);
        $parser->setExpression('entity.getCategory().getId() == 5');
        $parser->compile();

        self::assertNotEmpty($parser->getJoins());
        self::assertCount(1, $parser->getParameters());
    }

    public function testCompileWithMatchesRegex(): void
    {
        $parser = new ExpressionDqlParser();
        $parser->setDataClass('App\Common\Entity\Content');
        $parser->setValues(['entity' => '']);
        $parser->setExpression('entity.getTitle() matches "/test/"');
        $parser->compile();

        $where = $parser->getWhere();
        self::assertStringContainsString('REGEXP', $where);
    }

    public function testCompileWithNotOperator(): void
    {
        $parser = new ExpressionDqlParser();
        $parser->setDataClass('App\Common\Entity\Content');
        $parser->setValues(['entity' => '']);
        $parser->setExpression('!(entity.getId() == 1)');
        $parser->compile();

        $where = $parser->getWhere();
        self::assertNotSame('', $where);
    }

    public function testCompileWithBooleanTrue(): void
    {
        $parser = new ExpressionDqlParser();
        $parser->setDataClass('App\Common\Entity\Content');
        $parser->setValues(['entity' => '']);
        $parser->setExpression('entity.getEnabled() == true');
        $parser->compile();

        self::assertCount(1, $parser->getParameters());
    }

    public function testCompileWithBooleanFalse(): void
    {
        $parser = new ExpressionDqlParser();
        $parser->setDataClass('App\Common\Entity\Content');
        $parser->setValues(['entity' => '']);
        $parser->setExpression('entity.getEnabled() == false');
        $parser->compile();

        self::assertCount(1, $parser->getParameters());
    }

    public function testSetValuesDoesNotThrow(): void
    {
        $parser = new ExpressionDqlParser();
        $parser->setValues(['entity' => 'test']);
        $parser->setDataClass('App\Common\Entity\Content');
        $parser->setExpression('entity.getId() == 1');
        $parser->compile();

        self::assertStringContainsString('=', $parser->getWhere());
    }

    public function testRootAliasDefault(): void
    {
        $parser = new ExpressionDqlParser();
        self::assertSame('filter_entity', $parser->getRootAlias());
    }

    public function testGetWhereEmptyBeforeCompile(): void
    {
        $parser = new ExpressionDqlParser();
        self::assertSame('', $parser->getWhere());
    }

    public function testGetJoinsEmptyBeforeCompile(): void
    {
        $parser = new ExpressionDqlParser();
        self::assertSame([], $parser->getJoins());
    }
}
