<?php

namespace App\Tests\Wallet\Integration;

use App\Identity\Main\Entity\User;
use App\Wallet\Entity\Wallet;
use App\Wallet\Repository\WalletRepository;
use Doctrine\ORM\EntityManagerInterface;
use App\Tests\Integration\IntegrationKernelTestCase;
use App\Tests\Integration\DatabaseBootstrapTrait;

final class WalletRepositoryTest extends IntegrationKernelTestCase
{
    use DatabaseBootstrapTrait;

    private EntityManagerInterface $em;
    private WalletRepository $repo;

    protected function setUp(): void
    {
        $this->bootTestDatabase();
        self::bootKernel();

        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->createQuery('DELETE FROM App\\Wallet\\Entity\\Wallet')->execute();
        $this->em->createQuery('DELETE FROM App\\Identity\\Main\\Entity\\User')->execute();

        $this->repo = $this->em->getRepository(Wallet::class);
    }

    private function createUser(string $username = 'test'): User
    {
        $user = new User();
        $user->setEmail("$username@test.com");
        $user->setUsername($username);
        $user->setPassword('password');
        $this->em->persist($user);
        $this->em->flush();
        return $user;
    }

    private function createWallet(User $user, string $currency = 'USD', int $balance = 0): Wallet
    {
        $wallet = new Wallet($user->getUuid(), $currency);
        $this->em->persist($wallet);
        $this->em->flush();

        if ($balance > 0) {
            $this->em->createQuery('UPDATE App\Wallet\Entity\Wallet w SET w.balance = :b WHERE w.id = :id')
                ->setParameter('b', $balance)->setParameter('id', $wallet->getId())->execute();
            $this->em->refresh($wallet);
        }
        return $wallet;
    }

    public function testFindByIdReturnsWallet(): void
    {
        $user = $this->createUser('findbyid');
        $wallet = $this->createWallet($user, 'USD', 5000);

        $found = $this->repo->findById($wallet->getId());
        self::assertNotNull($found);
        self::assertSame($wallet->getId(), $found->getId());
        self::assertSame('USD', $found->getCurrency());
    }

    public function testFindByIdReturnsNull(): void
    {
        self::assertNull($this->repo->findById(999999));
    }

    public function testFindByIdForUpdatePessimisticLock(): void
    {
        $user = $this->createUser('locktest');
        $wallet = $this->createWallet($user, 'USD', 10000);

        $this->em->beginTransaction();
        try {
            $locked = $this->repo->findByIdForUpdate($wallet->getId());
            self::assertNotNull($locked);
            self::assertSame(10000, $locked->getBalance());
            $this->em->commit();
        } catch (\Throwable $e) {
            if ($this->em->getConnection()->isTransactionActive()) {
                $this->em->rollback();
            }
            throw $e;
        }
    }

    public function testFindByIdForUpdateReturnsNull(): void
    {
        $this->em->beginTransaction();
        try {
            self::assertNull($this->repo->findByIdForUpdate(999999));
            $this->em->commit();
        } catch (\Throwable $e) {
            if ($this->em->getConnection()->isTransactionActive()) {
                $this->em->rollback();
            }
            throw $e;
        }
    }

    public function testFindByOwnerUuid(): void
    {
        $user = $this->createUser('multiwallet');
        $this->createWallet($user, 'USD', 100);
        $this->createWallet($user, 'EUR', 200);
        $this->createWallet($user, 'GBP', 300);

        $wallets = $this->repo->findByOwnerUuid($user->getUuid());
        self::assertCount(3, $wallets);
        self::assertSame('EUR', $wallets[0]->getCurrency()); // sorted ASC
        self::assertSame('GBP', $wallets[1]->getCurrency());
        self::assertSame('USD', $wallets[2]->getCurrency());
    }

    public function testFindByOwnerUuidEmpty(): void
    {
        $wallets = $this->repo->findByOwnerUuid('missing-owner');
        self::assertIsArray($wallets);
        self::assertCount(0, $wallets);
    }

    public function testFindByOwnerUuidAndCurrency(): void
    {
        $user = $this->createUser('currency');
        $this->createWallet($user, 'USD', 100);
        $this->createWallet($user, 'EUR', 200);

        $found = $this->repo->findByOwnerUuidAndCurrency($user->getUuid(), 'EUR');
        self::assertNotNull($found);
        self::assertSame('EUR', $found->getCurrency());
        self::assertSame(200, $found->getBalance());
    }

    public function testFindByOwnerUuidAndCurrencyCaseInsensitive(): void
    {
        $user = $this->createUser('casing');
        $this->createWallet($user, 'BTC', 10000);

        $found = $this->repo->findByOwnerUuidAndCurrency($user->getUuid(), 'btc');
        self::assertNotNull($found);
        self::assertSame('BTC', $found->getCurrency());
    }

    public function testFindByOwnerUuidAndCurrencyNotFound(): void
    {
        $user = $this->createUser('nocoin');
        self::assertNull($this->repo->findByOwnerUuidAndCurrency($user->getUuid(), 'XYZ'));
    }
}
