<?php /** @noinspection PhpMissingParentConstructorInspection */

namespace App\Common\Controller\App;

use App\Common\Service\CommentServiceInterface;
use App\Core\Controller\RestController;
use App\Core\View\ApiView;
use App\Core\View\CreateApiViewMixin;
use App\Core\View\DetailApiViewMixin;
use App\Core\View\ListApiViewMixin;
use App\Identity\Entity\User;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1/app/comments', name: 'app-comments-')]
class CommentController extends RestController
{
    use ApiView, ListApiViewMixin, DetailApiViewMixin, CreateApiViewMixin;

    protected array $requiredCreateProperties = ['body', 'entityType', 'entityId'];
    protected array $acceptedCreateProperties = ['parent'];

    public function __construct(
        protected readonly CommentServiceInterface $service
    ) {}

    protected function commonFilter()
    {
        return [
            'author' => $this->getUser(), 
        ];
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
}
