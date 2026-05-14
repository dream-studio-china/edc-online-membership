<?php

namespace App\Common\Controller\App;

use App\Common\Entity\Content;
use App\Common\Service\ContentService;
use App\Core\Controller\RestController;
use App\Core\View\ApiView;
use App\Core\View\DetailApiViewMixin;
use App\Core\View\ListApiViewMixin;
use Doctrine\Common\Collections\ArrayCollection;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/api/v1/app/contents', name: 'app-contents-')]
class ContentController extends RestController
{
    use DetailApiViewMixin {
        detailAction as recordDetailAction;
    }
    use ApiView, ListApiViewMixin;

    public function __construct(
        RequestStack $requestStack,
        SerializerInterface $serializer,
        TranslatorInterface $translator,
        protected readonly ContentService $service
    ) {
        parent::__construct($requestStack, $serializer, $translator);
    }

    /**
     * @param $entities
     * @return array
     */
    protected function listResponses($entities): array
    {
        if(!$entities instanceof ArrayCollection) {
            $entities = new ArrayCollection($entities);
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

