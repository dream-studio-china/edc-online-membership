<?php /** @noinspection PhpMissingParentConstructorInspection */

namespace App\Common\Controller\Manage;

use App\Common\Service\PictureService;
use App\Core\Controller\RestController;
use App\Core\Security\UserUuidResolverInterface;
use App\Core\View\ApiView;
use App\Core\View\CreateApiViewMixin;
use App\Core\View\DeleteApiViewMixin;
use App\Core\View\DetailApiViewMixin;
use App\Core\View\ListApiViewMixin;
use App\Core\View\UpdateApiViewMixin;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/manage/pictures', name: 'manage-pictures-')]
#[IsGranted('ROLE_ADMIN')]
class PictureController extends RestController
{
    use ApiView, DetailApiViewMixin, ListApiViewMixin,
        CreateApiViewMixin, UpdateApiViewMixin, DeleteApiViewMixin;

    /** @var list<string> */
    protected array $requiredCreateProperties = ['category', 'image'];
    /** @var list<string> */
    protected array $acceptedCreateProperties = ['ownerUuid', 'user', 'title', 'category', 'image', 'metadata'];
    /** @var list<string> */
    protected array $acceptedUpdateProperties = ['ownerUuid', 'user', 'title', 'category', 'image', 'metadata'];

    public function __construct(
        protected readonly PictureService $service,
        private readonly UserUuidResolverInterface $userUuidResolver,
    ) {}

    /**
     * @param array<string, mixed> $content
     * @return array<string, mixed>
     */
    protected function processCreateContent(array $content, object $entity): array
    {
        return $this->resolveOwnerUuid($content);
    }

    /**
     * @param array<string, mixed> $content
     * @return array<string, mixed>
     */
    protected function processUpdateContent(array $content, ?object $entity = null): array
    {
        return $this->resolveOwnerUuid($content);
    }

    /**
     * @param array<string, mixed> $content
     * @return array<string, mixed>
     */
    private function resolveOwnerUuid(array $content): array
    {
        if (isset($content['user'])) {
            $ownerUuid = $this->userUuidResolver->resolveUserUuid((int) $content['user']);
            if ($ownerUuid === null) {
                throw new \InvalidArgumentException('User not found.');
            }
            $content['ownerUuid'] = $ownerUuid;
            unset($content['user']);
        }

        return $content;
    }
}
