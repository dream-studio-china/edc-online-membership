<?php

declare(strict_types=1);

namespace App\Identity\Wechat\Controller\App;

use App\Core\Controller\RestController;
use App\Core\Security\UserUuidPrincipalInterface;
use App\Core\View\ApiView;
use App\Core\View\CreateApiViewMixin;
use App\Core\View\DeleteApiViewMixin;
use App\Core\View\DetailApiViewMixin;
use App\Core\View\ListApiViewMixin;
use App\Core\View\UpdateApiViewMixin;
use App\Identity\Wechat\Service\WechatUserServiceInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/app/wechat-users', name: 'app-wechat-users-')]
#[IsGranted('ROLE_USER')]
class WechatUserController extends RestController
{
    use ApiView,
        DetailApiViewMixin,
        ListApiViewMixin,
        CreateApiViewMixin,
        UpdateApiViewMixin,
        DeleteApiViewMixin;

    public function __construct(
        protected readonly WechatUserServiceInterface $service
    ) {}

    /** @return array<string, mixed> */
    protected function commonFilter(): array
    {
        $user = $this->getUser();

        return $user instanceof UserUuidPrincipalInterface ? ['userUuid' => $user->getUuid()] : ['id' => -1];
    }
}
