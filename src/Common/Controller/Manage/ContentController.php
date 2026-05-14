<?php

namespace App\Common\Controller\Manage;

use App\Common\Service\ContentService;
use App\Core\Controller\RestController;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use App\Core\View\ApiView;
use App\Core\View\CreateApiViewMixin;
use App\Core\View\DeleteApiViewMixin;
use App\Core\View\DetailApiViewMixin;
use App\Core\View\ListApiViewMixin;
use App\Core\View\UpdateApiViewMixin;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1/manage/contents', name: 'manage-contents-')]
class ContentController extends RestController
{
    use ApiView, DetailApiViewMixin, ListApiViewMixin,
        CreateApiViewMixin, UpdateApiViewMixin, DeleteApiViewMixin;

    public function __construct(
        RequestStack $requestStack,
        SerializerInterface $serializer,
        TranslatorInterface $translator,
        protected readonly ContentService $service
    ) {
        parent::__construct($requestStack, $serializer, $translator);
    }
}
