<?php /** @noinspection PhpMissingParentConstructorInspection */

namespace App\Common\Controller\App;

use App\Common\Service\PageServiceInterface;
use App\Core\Controller\RestController;
use App\Core\View\ApiView;
use App\Core\View\DetailApiViewMixin;
use App\Core\View\ListApiViewMixin;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1/app/pages', name: 'app-pages-')]
class PageController extends RestController
{
    use ApiView, DetailApiViewMixin, ListApiViewMixin;

    public function __construct(
        protected readonly PageServiceInterface $service
    ) {}

    protected function commonFilter()
    {
        return ['status' => 'published'];
    }

    protected function detailFilter($filter = null)
    {
        if (is_array($filter)) {
            unset($filter['status']);
        }
        return $filter;
    }
}
