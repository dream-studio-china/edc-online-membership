<?php

namespace App\Tests\Integration;

use App\Common\Entity\Content;
use App\Core\Parser\ExpressionDqlParser;
use App\Core\Service\ExpressionService;
use Doctrine\ORM\EntityManagerInterface;

final class CoreServiceIntegrationTest extends IntegrationKernelTestCase
{
    use DatabaseBootstrapTrait;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->bootTestDatabase();
        self::bootKernel();

        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    public function testExpressionServiceBuildFilterAgainstRealDoctrineMetadata(): void
    {
        $service = new ExpressionService();

        $result = $service->buildFilter(
            'entity.getId() == 1',
            Content::class,
            ['entity' => ''],
            $this->em
        );

        self::assertArrayHasKey('qb', $result);
        self::assertArrayHasKey('parameters', $result);
        self::assertNotEmpty($result['parameters']);
    }

    public function testExpressionServiceBuildsRegexpFilter(): void
    {
        $service = new ExpressionService();

        $result = $service->buildFilter(
            "entity.getTitle() matches 'paper'",
            Content::class,
            ['entity' => ''],
            $this->em
        );

        self::assertArrayHasKey('qb', $result);
        self::assertStringContainsString('REGEXP', $result['qb']->getDQL());
        self::assertStringContainsString('REGEXP', $result['qb']->getQuery()->getSQL());
    }

    public function testCustomDqlFunctionsAreRegistered(): void
    {
        $randSql = $this->em
            ->createQuery('SELECT RAND() FROM ' . Content::class . ' content')
            ->getSQL();
        $seededRandSql = $this->em
            ->createQuery('SELECT RAND(1) FROM ' . Content::class . ' content')
            ->getSQL();
        $dateFormatSql = $this->em
            ->createQuery("SELECT DATE_FORMAT(content.createdAt, '%Y-%m') FROM " . Content::class . ' content')
            ->getSQL();

        self::assertStringContainsString('RAND()', $randSql);
        self::assertStringContainsString('RAND(1)', $seededRandSql);
        self::assertStringContainsString('DATE_FORMAT', $dateFormatSql);
    }

    public function testExpressionDqlParserValidateFragmentsWithDoctrineMetadata(): void
    {
        $parser = new ExpressionDqlParser();
        $parser->setDataClass(Content::class)
            ->setValues(['entity' => ''])
            ->setExpression('entity.getId() == 2')
            ->compile();

        $parser->validateFragments($this->em);

        self::assertStringContainsString('filter_entity.id', $parser->getWhere());
    }
}
