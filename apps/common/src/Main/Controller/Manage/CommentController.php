<?php /** @noinspection PhpMissingParentConstructorInspection */

namespace App\Common\Controller\Manage;

use App\Common\Service\CommentService;
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

#[Route('/manage/comments', name: 'manage-comments-')]
#[IsGranted('ROLE_ADMIN')]
class CommentController extends RestController
{
    use ApiView, DetailApiViewMixin, ListApiViewMixin,
        CreateApiViewMixin, UpdateApiViewMixin, DeleteApiViewMixin;

    /** @var list<string> */
    protected array $requiredCreateProperties = ['body', 'entityType', 'entityId'];
    /** @var list<string> */
    protected array $acceptedCreateProperties = ['body', 'entityType', 'entityId', 'authorName', 'authorEmail', 'authorUuid', 'author', 'parent', 'status'];
    /** @var list<string> */
    protected array $acceptedUpdateProperties = ['body', 'authorName', 'authorEmail', 'authorUuid', 'author', 'status'];

    public function __construct(
        protected readonly CommentService $service,
        private readonly UserUuidResolverInterface $userUuidResolver,
    ) {}

    /**
     * @param array<string, mixed> $content
     * @return array<string, mixed>
     */
    protected function processCreateContent(array $content, object $entity): array
    {
        return $this->resolveAuthorUuid($content);
    }

    /**
     * @param array<string, mixed> $content
     * @return array<string, mixed>
     */
    protected function processUpdateContent(array $content, ?object $entity = null): array
    {
        return $this->resolveAuthorUuid($content);
    }

    /**
     * @param array<string, mixed> $content
     * @return array<string, mixed>
     */
    private function resolveAuthorUuid(array $content): array
    {
        if (isset($content['author'])) {
            $authorUuid = $this->userUuidResolver->resolveUserUuid((int) $content['author']);
            if ($authorUuid === null) {
                throw new \InvalidArgumentException('User not found.');
            }
            $content['authorUuid'] = $authorUuid;
            unset($content['author']);
        }

        return $content;
    }
}
