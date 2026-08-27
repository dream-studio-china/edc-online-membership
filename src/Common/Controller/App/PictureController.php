<?php /** @noinspection PhpMissingParentConstructorInspection */

namespace App\Common\Controller\App;

use App\Common\Service\PictureServiceInterface;
use App\Core\Controller\RestController;
use App\Core\View\ApiView;
use App\Core\View\CreateApiViewMixin;
use App\Core\View\DeleteApiViewMixin;
use App\Core\View\DetailApiViewMixin;
use App\Core\View\ListApiViewMixin;
use App\Core\View\UpdateApiViewMixin;
use App\Identity\Entity\User;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/app/pictures', name: 'app-pictures-')]
class PictureController extends RestController
{
    use ApiView, DetailApiViewMixin, ListApiViewMixin,
        CreateApiViewMixin, UpdateApiViewMixin, DeleteApiViewMixin;

    /** @var list<string> */
    protected array $requiredCreateProperties = ['category', 'image'];
    /** @var list<string> */
    protected array $acceptedCreateProperties = ['title', 'category', 'image', 'metadata'];
    /** @var list<string> */
    protected array $acceptedUpdateProperties = ['title', 'category', 'image', 'metadata'];

    public function __construct(
        protected readonly PictureServiceInterface $service
    ) {}

    /** @return array<string, mixed> */
    protected function commonFilter(): array
    {
        $user = $this->getUser();

        return $user instanceof User ? ['user' => $user] : ['id' => -1];
    }

    /**
     * @param array<string, mixed> $content
     */
    protected function processEntity(array $content, object $entity): object
    {
        $user = $this->getUser();
        if ($user instanceof User && method_exists($entity, 'setUser')) {
            $entity->setUser($user);
        }

        return $entity;
    }
}
