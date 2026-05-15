<?php /** @noinspection PhpMissingParentConstructorInspection */

namespace App\Common\Controller\App;

use App\Common\Entity\Comment;
use App\Common\Service\CommentServiceInterface;
use App\Core\Controller\RestController;
use App\Core\Service\BaseService;
use App\Core\View\ApiView;
use App\Core\View\CreateApiViewMixin;
use App\Core\View\DetailApiViewMixin;
use App\Core\View\ListApiViewMixin;
use App\Identity\Entity\User;
use Doctrine\Common\Collections\ArrayCollection;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1/app/comments', name: 'app-comments-')]
class CommentController extends RestController
{
    use ApiView, ListApiViewMixin, DetailApiViewMixin, CreateApiViewMixin;

    protected array $requiredCreateProperties = ['body', 'entityType', 'entityId'];
    protected array $acceptedCreateProperties = ['body', 'entityType', 'entityId', 'parent'];

    public function __construct(
        protected readonly CommentServiceInterface $service
    ) {}

    protected function commonFilter()
    {
        return ['status' => 'approved'];
    }

    protected function detailFilter($filter = null)
    {
        if (is_array($filter)) {
            unset($filter['status']);
        }
        return $filter;
    }

    protected function defaultCreateValues(): array
    {
        $user = $this->getUser();

        if ($user instanceof User) {
            return [
                'status' => 'pending',
                'author' => $user->getId(),
                'authorName' => $user->getUsername(),
                'authorEmail' => $user->getEmail(),
            ];
        }

        return ['status' => 'pending'];
    }

    protected function listResponses($entities): array
    {
        if (!$entities instanceof ArrayCollection) {
            $entities = BaseService::listResultToCollection($entities);
        }

        return $entities
            ->map(function (Comment $comment) {
                return [
                    'id' => $comment->getId(),
                    'body' => $comment->getBody(),
                    'authorName' => $comment->getAuthorName(),
                    'authorEmail' => $comment->getAuthorEmail(),
                    'entityType' => $comment->getEntityType(),
                    'entityId' => $comment->getEntityId(),
                    'parent' => $comment->getParent() ? [
                        'id' => $comment->getParent()->getId(),
                    ] : null,
                    'status' => $comment->getStatus(),
                    'createdAt' => $comment->getCreatedAt(),
                ];
            })
            ->toArray();
    }
}
