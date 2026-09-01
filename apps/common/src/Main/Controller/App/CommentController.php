<?php /** @noinspection PhpMissingParentConstructorInspection */

namespace App\Common\Controller\App;

use App\Common\Service\CommentServiceInterface;
use App\Core\Controller\RestController;
use App\Core\Security\UserUuidPrincipalInterface;
use App\Core\View\ApiView;
use App\Core\View\CreateApiViewMixin;
use App\Core\View\DetailApiViewMixin;
use App\Core\View\ListApiViewMixin;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/app/comments', name: 'app-comments-')]
class CommentController extends RestController
{
    use ApiView, ListApiViewMixin, DetailApiViewMixin, CreateApiViewMixin;

    /** @var list<string> */
    protected array $requiredCreateProperties = ['body', 'entityType', 'entityId'];
    /** @var list<string> */
    protected array $acceptedCreateProperties = ['parent'];

    public function __construct(
        protected readonly CommentServiceInterface $service
    ) {}

    /** @return array<string, mixed> */
    protected function commonFilter(): array
    {
        $user = $this->getUser();

        return $user instanceof UserUuidPrincipalInterface ? ['authorUuid' => $user->getUuid()] : ['id' => -1];
    }

    /** @return array<string, mixed> */
    protected function defaultCreateValues(): array
    {
        $user = $this->getUser();

        if ($user instanceof UserUuidPrincipalInterface) {
            return [
                'status' => 'pending',
                'authorUuid' => $user->getUuid(),
                'authorName' => method_exists($user, 'getUsername') ? $user->getUsername() : null,
                'authorEmail' => method_exists($user, 'getEmail') ? $user->getEmail() : null,
            ];
        }

        return ['status' => 'pending'];
    }
}
