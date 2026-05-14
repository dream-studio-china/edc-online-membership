<?php /** @noinspection PhpMissingParentConstructorInspection */

namespace App\Common\Controller\App;

use App\Common\Entity\Content;
use App\Common\Service\ContentServiceInterface;
use App\Core\Controller\RestController;
use App\Core\Service\BaseService;
use App\Core\View\ApiView;
use App\Core\View\DetailApiViewMixin;
use App\Core\View\ListApiViewMixin;
use Doctrine\Common\Collections\ArrayCollection;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1/app/contents', name: 'app-contents-')]
class ContentController extends RestController
{
    use ApiView, DetailApiViewMixin, ListApiViewMixin;

    public function __construct(
        protected readonly ContentServiceInterface $service
    ) {}

    /**
     * @param $entities
     * @return array
     */
    protected function listResponses($entities): array
    {
        if(!$entities instanceof ArrayCollection) {
            $entities = BaseService::listResultToCollection($entities);
        }

        return $entities
            ->map(function (Content $content) {
                return [
                    'id' => $content->getId(),
                    'title' => $content->getTitle(),
                    'createdAt' => $content->getCreatedAt(),
                ];
            })
            ->toArray();
    }
}

