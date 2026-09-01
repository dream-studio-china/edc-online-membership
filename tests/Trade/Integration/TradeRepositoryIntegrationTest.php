<?php

declare(strict_types=1);

namespace App\Tests\Trade\Integration;

use App\Tests\Integration\DatabaseBootstrapTrait;
use App\Trade\Entity\Product;
use App\Trade\Entity\Specification;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class TradeRepositoryIntegrationTest extends KernelTestCase
{
    use DatabaseBootstrapTrait;

    protected function setUp(): void
    {
        $this->bootTestDatabase();
        self::bootKernel();
    }

    public function testProductRepositoryFindNotDeleted(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $product = new Product();
        $product->setName('Repo Product');
        $em->persist($product);
        $em->flush();

        $repo = $em->getRepository(Product::class);
        $notDeleted = $repo->findNotDeleted();
        self::assertNotEmpty($notDeleted);

        $active = $repo->findActive();
        self::assertNotEmpty($active);
    }

    public function testProductRepositoryFindDeleted(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $product = new Product();
        $product->setName('Deleted');
        $product->setIsDeleted(true);
        $em->persist($product);
        $em->flush();

        $repo = $em->getRepository(Product::class);
        $notDeleted = $repo->findNotDeleted();
        $foundDeleted = false;
        foreach ($notDeleted as $p) {
            if ($p->getIsDeleted()) {
                $foundDeleted = true;
            }
        }
        self::assertFalse($foundDeleted);
    }

    public function testSpecificationRepositoryFindByProduct(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $product = new Product();
        $product->setName('Spec Product');
        $em->persist($product);

        $spec = new Specification();
        $spec->setProduct($product);
        $spec->setName('Spec A');
        $spec->setPrice(100);
        $em->persist($spec);
        $em->flush();

        $specRepo = $em->getRepository(Specification::class);
        $byProduct = $specRepo->findByProduct($product->getId() ?? 0);
        self::assertNotEmpty($byProduct);

        $activeByProduct = $specRepo->findActiveByProduct($product->getId() ?? 0);
        self::assertNotEmpty($activeByProduct);
    }

    public function testOrderRepositoryFindByUserUuid(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $repo = $em->getRepository(\App\Trade\Entity\Order::class);
        $orders = $repo->findByUserUuid('00000000-0000-4000-8000-000000000000');
        self::assertIsArray($orders);
    }
}
