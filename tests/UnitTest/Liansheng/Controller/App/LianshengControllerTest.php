<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Liansheng\Controller\App;

use App\Liansheng\Controller\App\LianshengController;
use App\Liansheng\Exception\LianshengApiException;
use App\Liansheng\Service\LianshengServiceInterface;
use App\Identity\Entity\User;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AllowMockObjectsWithoutExpectations]
final class LianshengControllerTest extends TestCase
{
    public function testReturnsStoreWithoutChangingItsShape(): void
    {
        $service = $this->createMock(LianshengServiceInterface::class);
        $service->expects(self::once())->method('getStore')->willReturn([
            'storeCode' => 'S001',
            'storeName' => 'Test Store',
        ]);
        $controller = $this->controller($service);

        $response = $controller->store();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('S001', $this->decode($response->getContent())['data']['storeCode']);
    }

    public function testRequiresMemberMobile(): void
    {
        $service = $this->createMock(LianshengServiceInterface::class);
        $service->expects(self::never())->method('getMemberByMobile');
        $controller = $this->controller($service, new User());

        $response = $controller->member();

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('Current user must have a phone number.', $this->decode($response->getContent())['message']);
    }

    public function testReturnsMemberAndMapsProviderFailure(): void
    {
        $service = $this->createMock(LianshengServiceInterface::class);
        $service->expects(self::exactly(2))->method('getMemberByMobile')
            ->with('13802542123')
            ->willReturnOnConsecutiveCalls(
                ['code' => '51728662', 'mobile' => '13802542123'],
                self::throwException(new LianshengApiException('provider unavailable')),
            );
        $controller = $this->controller($service, $this->user('13802542123'));

        $success = $controller->member();
        $failure = $controller->member();

        self::assertSame('51728662', $this->decode($success->getContent())['data']['code']);
        self::assertSame(502, $failure->getStatusCode());
        self::assertSame('provider unavailable', $this->decode($failure->getContent())['message']);
    }

    public function testReturnsMemberCardTypes(): void
    {
        $service = $this->createMock(LianshengServiceInterface::class);
        $service->expects(self::once())->method('getMemberCardTypes')->willReturn([
            ['id' => '3032191', 'name' => 'VIP会员'],
        ]);
        $controller = $this->controller($service);

        $response = $controller->memberCardTypes();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('3032191', $this->decode($response->getContent())['data'][0]['id']);
    }

    // TEMP-DISABLED: auto-register is commented out in App\LianshengController::member()
    // until supplier 0702 stops returning code 500 with null msg. Missing members
    // now return empty data instead of registering.
    public function testReturnsEmptyProfileWhenMemberIsMissing(): void
    {
        $service = $this->createMock(LianshengServiceInterface::class);
        $service->expects(self::once())->method('getMemberByMobile')
            ->with('13802542123')
            ->willReturn([]);
        $service->expects(self::never())->method('registerMemberByMobile');
        $controller = $this->controller($service, $this->user('13802542123'));

        $response = $controller->member();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([], $this->decode($response->getContent())['data']);
    }

    public function testReturnsMemberScoreBookByVipId(): void
    {
        $service = $this->createMock(LianshengServiceInterface::class);
        $service->expects(self::once())->method('getMemberScoreBook')
            ->with(null, '1260735')
            ->willReturn(['totalCount' => 1, 'list' => [['creditscore' => 10]]]);
        $controller = $this->controller($service);

        $response = $controller->memberScoreBook(Request::create(
            '/api/v1/app/liansheng/member-scorebook',
            'GET',
            ['vipId' => '1260735'],
        ));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(1, $this->decode($response->getContent())['data']['totalCount']);
    }

    public function testRequiresExactlyOneMemberScoreBookIdentifier(): void
    {
        $service = $this->createMock(LianshengServiceInterface::class);
        $service->expects(self::never())->method('getMemberScoreBook');
        $controller = $this->controller($service);

        $response = $controller->memberScoreBook(Request::create('/api/v1/app/liansheng/member-scorebook'));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('Exactly one of mobile or vipId is required.', $this->decode($response->getContent())['message']);
    }

    private function controller(LianshengServiceInterface $service, ?User $user = null): LianshengController
    {
        $controller = new LianshengController($service);
        $serializer = $this->createMock(SerializerInterface::class);
        $serializer->method('serialize')->willReturnCallback(
            static fn (mixed $data): string => json_encode($data, JSON_THROW_ON_ERROR),
        );
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);
        $tokenStorage = $this->createMock(TokenStorageInterface::class);
        $tokenStorage->method('getToken')->willReturn(
            $user === null ? null : new UsernamePasswordToken($user, 'main', $user->getRoles()),
        );
        $container = new Container();
        $container->set('security.token_storage', $tokenStorage);
        $controller->setContainer($container);
        $controller->setSerializer($serializer);
        $controller->setTranslator($translator);

        return $controller;
    }

    private function user(string $phone): User
    {
        return (new User())
            ->setEmail('member@example.test')
            ->setUsername('member')
            ->setPhone($phone)
            ->setPhoneVerified(true);
    }

    /** @return array<string, mixed> */
    private function decode(string $content): array
    {
        /** @var array<string, mixed> */
        return json_decode($content, true, 512, JSON_THROW_ON_ERROR);
    }
}
