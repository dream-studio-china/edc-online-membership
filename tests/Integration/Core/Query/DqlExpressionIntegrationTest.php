<?php

declare(strict_types=1);

namespace App\Tests\Integration\Core\Query;

use App\Core\Query\DqlExpression;
use App\Identity\Entity\User;
use App\Tests\Integration\DatabaseBootstrapTrait;
use App\Tests\Integration\IntegrationKernelTestCase;
use App\Wallet\Entity\Wallet;
use Doctrine\ORM\EntityManagerInterface;

final class DqlExpressionIntegrationTest extends IntegrationKernelTestCase
{
    use DatabaseBootstrapTrait;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->bootTestDatabase();
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    private function createUser(string $email, string $username): User
    {
        $u = new User();
        $u->setEmail($email);
        $u->setUsername($username);
        $u->setPassword('x');
        $this->em->persist($u);
        $this->em->flush();

        return $u;
    }

    public function testListFiltersByUserEquality(): void
    {
        $userA = $this->createUser('dql-a-'.uniqid().'@example.com', 'dql-a-'.uniqid());
        $userB = $this->createUser('dql-b-'.uniqid().'@example.com', 'dql-b-'.uniqid());

        $wA1 = new Wallet($userA, 'CNY');
        $wA2 = new Wallet($userA, 'USD');
        $wB1 = new Wallet($userB, 'CNY');
        $this->em->persist($wA1); $this->em->persist($wA2); $this->em->persist($wB1);
        $this->em->flush();

        $walletService = static::getContainer()->get(\App\Wallet\Service\WalletServiceInterface::class);

        $exprA = new DqlExpression('entity.getUser() == user', ['user' => $userA]);
        $resultA = $walletService->list($exprA, null, true);
        $itemsA = $resultA instanceof \Doctrine\ORM\QueryBuilder ? $resultA->getQuery()->getResult() : (is_array($resultA) ? $resultA : []);
        self::assertCount(2, $itemsA);

        $exprB = new DqlExpression('entity.getUser() == user', ['user' => $userB]);
        $resultB = $walletService->list($exprB, null, true);
        $itemsB = $resultB instanceof \Doctrine\ORM\QueryBuilder ? $resultB->getQuery()->getResult() : (is_array($resultB) ? $resultB : []);
        self::assertCount(1, $itemsB);
    }

    public function testGetWithCriteriaIsolatesRow(): void
    {
        $userA = $this->createUser('dql-get-a-'.uniqid().'@example.com', 'dql-get-a-'.uniqid());
        $userB = $this->createUser('dql-get-b-'.uniqid().'@example.com', 'dql-get-b-'.uniqid());

        $wA = new Wallet($userA, 'CNY');
        $wB = new Wallet($userB, 'CNY');
        $this->em->persist($wA); $this->em->persist($wB); $this->em->flush();

        $svc = static::getContainer()->get(\App\Wallet\Service\WalletServiceInterface::class);

        $own = $svc->get((new DqlExpression('entity.getUser() == user', ['user' => $userA]))->withCriteria(['id' => $wA->getId()]));
        self::assertNotNull($own);
        self::assertSame($wA->getId(), $own->getId());

        $other = $svc->get((new DqlExpression('entity.getUser() == user', ['user' => $userA]))->withCriteria(['id' => $wB->getId()]));
        self::assertNull($other);
    }

    public function testThisBindingViaControllerContext(): void
    {
        $userA = $this->createUser('dql-this-a-'.uniqid().'@example.com', 'dql-this-a-'.uniqid());
        $userB = $this->createUser('dql-this-b-'.uniqid().'@example.com', 'dql-this-b-'.uniqid());
        $wA = new Wallet($userA, 'CNY'); $this->em->persist($wA);
        $wB = new Wallet($userB, 'CNY'); $this->em->persist($wB);
        $this->em->flush();

        $svc = static::getContainer()->get(\App\Wallet\Service\WalletServiceInterface::class);

        $controller = new class($userA) {
            public function __construct(private $u) {}
            public function getUser(){ return $this->u; }
        };
        $expr = (new DqlExpression('entity.getUser() == this.getUser()'))->withContext($controller);
        $result = $svc->list($expr, null, true);
        $items = $result instanceof \Doctrine\ORM\QueryBuilder ? $result->getQuery()->getResult() : (is_array($result) ? $result : []);
        self::assertCount(1, $items);
        self::assertSame($wA->getId(), $items[0]->getId());
    }

    public function testCombinedCriteriaAndComparison(): void
    {
        $user = $this->createUser('dql-comb-'.uniqid().'@example.com', 'dql-comb-'.uniqid());
        $w1 = new Wallet($user, 'CNY');
        $w2 = new Wallet($user, 'USD');
        $w3 = new Wallet($user, 'EUR');
        $this->em->persist($w1); $this->em->persist($w2); $this->em->persist($w3);
        $this->em->flush();

        $svc = static::getContainer()->get(\App\Wallet\Service\WalletServiceInterface::class);
        $expr = new DqlExpression('entity.getUser() == user && entity.getCurrency() == cur', ['user'=>$user, 'cur'=>'USD']);
        $result = $svc->list($expr, null, true);
        $items = $result instanceof \Doctrine\ORM\QueryBuilder ? $result->getQuery()->getResult() : (is_array($result) ? $result : []);
        self::assertCount(1, $items);
        self::assertSame('USD', $items[0]->getCurrency());
    }

    public function testApiViewMixesIdCriteriaIntoDqlExpression(): void
    {
        $userA = $this->createUser('dql-api-a-'.uniqid().'@example.com', 'dql-api-a-'.uniqid());
        $userB = $this->createUser('dql-api-b-'.uniqid().'@example.com', 'dql-api-b-'.uniqid());
        $wA = new Wallet($userA, 'CNY'); $this->em->persist($wA);
        $wB = new Wallet($userB, 'CNY'); $this->em->persist($wB);
        $this->em->flush();

        $svc = static::getContainer()->get(\App\Wallet\Service\WalletServiceInterface::class);

        $controller = new class($userA) {
            use \App\Core\View\ApiView;
            public function __construct(private $u) {}
            public function getUser(){ return $this->u; }
            protected function commonFilter(): DqlExpression { return new DqlExpression('entity.getUser() == this.getUser()'); }
            public function exposeMix($id){ return $this->mixIdToCommonFilter($id); }
        };

        $mixedOwn = $controller->exposeMix($wA->getId());
        self::assertInstanceOf(DqlExpression::class, $mixedOwn);
        $foundOwn = $svc->get($mixedOwn);
        self::assertNotNull($foundOwn);
        self::assertSame($wA->getId(), $foundOwn->getId());

        $mixedOther = $controller->exposeMix($wB->getId());
        $foundOther = $svc->get($mixedOther);
        self::assertNull($foundOther);
    }

    public function testThisWithoutContextIsRejected(): void
    {
        $svc = static::getContainer()->get(\App\Wallet\Service\WalletServiceInterface::class);
        $expr = new DqlExpression('entity.getUser() == this.getUser()');
        $this->expectException(\LogicException::class);
        $svc->list($expr, null, true);
    }

    public function testInvalidFieldIsRejected(): void
    {
        $user = $this->createUser('dql-bad-field-'.uniqid().'@example.com', 'dql-bad-'.uniqid());
        $svc = static::getContainer()->get(\App\Wallet\Service\WalletServiceInterface::class);
        $expr = new DqlExpression('entity.getNotExisting() == user', ['user' => $user]);
        $this->expectException(\LogicException::class);
        $svc->list($expr, null, true);
    }

    public function testUndefinedVariableIsRejected(): void
    {
        $svc = static::getContainer()->get(\App\Wallet\Service\WalletServiceInterface::class);
        $expr = new DqlExpression('entity.getUser() == unknownVar');
        $this->expectException(\LogicException::class);
        $svc->list($expr, null, true);
    }

    public function testListWithInvalidCriteriaFieldIsRejected(): void
    {
        $user = $this->createUser('dql-crit-'.uniqid().'@example.com', 'dql-crit-'.uniqid());
        $svc = static::getContainer()->get(\App\Wallet\Service\WalletServiceInterface::class);
        $expr = (new DqlExpression('entity.getUser() == user', ['user'=>$user]))->withCriteria(['notExisting'=>1]);
        $this->expectException(\LogicException::class);
        $svc->list($expr, null, true);
    }

    public function testUpdateAndDeleteAlsoEnforceScope(): void
    {
        $userA = $this->createUser('dql-upd-a-'.uniqid().'@example.com', 'dql-upd-a-'.uniqid());
        $userB = $this->createUser('dql-upd-b-'.uniqid().'@example.com', 'dql-upd-b-'.uniqid());
        $wA = new Wallet($userA, 'CNY'); $this->em->persist($wA);
        $wB = new Wallet($userB, 'CNY'); $this->em->persist($wB);
        $this->em->flush();

        $svc = static::getContainer()->get(\App\Wallet\Service\WalletServiceInterface::class);

        // Simulate detail/get enforcement for delete and update lookup
        $exprForA = new DqlExpression('entity.getUser() == user', ['user'=>$userA]);
        $qbForA = $svc->list($exprForA, null, true);
        $itemsForA = $qbForA instanceof \Doctrine\ORM\QueryBuilder ? $qbForA->getQuery()->getResult() : [];
        // delete path: get via DqlExpression with id
        $foundBViaA = $svc->get((new DqlExpression('entity.getUser() == user', ['user'=>$userA]))->withCriteria(['id'=>$wB->getId()]));
        self::assertNull($foundBViaA, 'user A must not see user B wallet even via get');

        // update scenario: ensure update lookup also isolated
        $foundA = $svc->get((new DqlExpression('entity.getUser() == user', ['user'=>$userA]))->withCriteria(['id'=>$wA->getId()]));
        self::assertNotNull($foundA);
    }
}
