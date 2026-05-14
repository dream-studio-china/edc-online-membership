<?php

namespace App\Tests\Integration;

use App\Common\Entity\Content;
use App\Core\Parser\ExpressionDqlParser;
use App\Core\Service\ExpressionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CoreServiceIntegrationTest extends KernelTestCase
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
