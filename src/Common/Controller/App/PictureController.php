<?php /** @noinspection PhpMissingParentConstructorInspection */

namespace App\Common\Controller\App;

use App\Common\Entity\Picture;
use App\Common\Service\PictureServiceInterface;
use App\Core\Controller\RestController;
use App\Core\View\ApiView;
use App\Core\View\CreateApiViewMixin;
use App\Core\View\DeleteApiViewMixin;
use App\Core\View\DetailApiViewMixin;
use App\Core\View\ListApiViewMixin;
use App\Core\View\UpdateApiViewMixin;
use App\Identity\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
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
        protected readonly PictureServiceInterface $service,
        private readonly ?EntityManagerInterface $entityManager = null
    ) {}

    /** @return array<string, mixed>|QueryBuilder */
    protected function commonFilter(): array|QueryBuilder
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return ['id' => -1];
        }
        if ($this->entityManager === null) {
            return ['user' => $user];
        }
        // 个人图片按上传人隔离；未绑定上传人的全局配置图（如会员卡背景）对所有会员可见
        return $this->entityManager->createQueryBuilder()
            ->select('entity')
            ->from(Picture::class, 'entity')
            ->where('entity.user = :u OR entity.user IS NULL')
            ->setParameter('u', $user);
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
