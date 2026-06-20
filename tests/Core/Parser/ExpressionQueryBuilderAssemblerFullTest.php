<?php

declare(strict_types=1);

namespace App\Tests\Core\Parser;

use App\Core\Parser\ExpressionDqlParser;
use App\Core\Parser\ExpressionQueryBuilderAssembler;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\Parameter;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\TestCase;

final class ExpressionQueryBuilderAssemblerFullTest extends TestCase
{
    private function createEm(): EntityManagerInterface
    {
        $em = $this->createMock(EntityManagerInterface::class);

        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('select')->willReturn($qb);
        $qb->method('from')->willReturn($qb);
        $qb->method('andWhere')->willReturn($qb);
        $qb->method('setParameter')->willReturn($qb);
        $qb->method('leftJoin')->willReturn($qb);
        $qb->method('getRootAliases')->willReturn(['e']);
        $qb->method('getAllAliases')->willReturn(['e']);
        $qb->method('getParameters')->willReturn(new \Doctrine\Common\Collections\ArrayCollection([
            new Parameter('existing', 'val')
        ]));

        $em->method('createQueryBuilder')->willReturn($qb);
        return $em;
    }

    public function testBuildQueryBuilder(): void
    {
        $parser = new ExpressionDqlParser();
        $parser->setDataClass('App\Common\Entity\Content');
        $parser->setValues(['entity' => '']);
        $parser->setExpression('entity.getId() == 1');
        $parser->compile();

        $assembler = new ExpressionQueryBuilderAssembler($this->createEm());
        $qb = $assembler->buildQueryBuilder($parser);

        self::assertInstanceOf(QueryBuilder::class, $qb);
    }

    public function testBuildQueryBuilderWithOptions(): void
    {
        $parser = new ExpressionDqlParser();
        $parser->setDataClass('App\Common\Entity\Content');
        $parser->setValues(['entity' => '']);
        $parser->setExpression('entity.getId() == 1');
        $parser->compile();

        $assembler = new ExpressionQueryBuilderAssembler($this->createEm());
        $qb = $assembler->buildQueryBuilder($parser, ['rootAlias' => 'custom']);

        self::assertInstanceOf(QueryBuilder::class, $qb);
    }

    public function testBuildQueryBuilderWithEmptyDataClass(): void
    {
        $parser = $this->createMock(ExpressionDqlParser::class);
        $parser->method('getDataClass')->willReturn('');

        $assembler = new ExpressionQueryBuilderAssembler($this->createEm());

        $this->expectException(\Symfony\Component\Validator\Exception\ValidatorException::class);
        $assembler->buildQueryBuilder($parser);
    }

    public function testApplyToQueryBuilder(): void
    {
        $parser = new ExpressionDqlParser();
        $parser->setDataClass('App\Common\Entity\Content');
        $parser->setValues(['entity' => '']);
        $parser->setExpression('entity.getId() == 1');
        $parser->compile();

        $assembler = new ExpressionQueryBuilderAssembler($this->createEm());
        $qb = $assembler->buildQueryBuilder($parser);
        $result = $assembler->applyToQueryBuilder($qb, $parser);

        self::assertInstanceOf(QueryBuilder::class, $result);
    }

    public function testApplyFragmentsWithJoinsAndParams(): void
    {
        $parser = new ExpressionDqlParser();
        $parser->setDataClass('App\Common\Entity\Content');
        $parser->setValues(['entity' => '']);
        $parser->setExpression('entity.getCategory().getId() == 1');
        $parser->compile();

        $assembler = new ExpressionQueryBuilderAssembler($this->createEm());
        $qb = $assembler->buildQueryBuilder($parser);

        self::assertInstanceOf(QueryBuilder::class, $qb);
    }

    public function testApplyToQueryBuilderWithTargetAliasOption(): void
    {
        $parser = new ExpressionDqlParser();
        $parser->setDataClass('App\Common\Entity\Content');
        $parser->setValues(['entity' => '']);
        $parser->setExpression('entity.getId() == 1');
        $parser->compile();

        $assembler = new ExpressionQueryBuilderAssembler($this->createEm());
        $qb = $assembler->buildQueryBuilder($parser);
        $result = $assembler->applyToQueryBuilder($qb, $parser, ['targetRootAlias' => 'other']);

        self::assertInstanceOf(QueryBuilder::class, $result);
    }
}
