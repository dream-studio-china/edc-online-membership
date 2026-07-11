<?php /** @noinspection PhpMissingParentConstructorInspection */

namespace App\Common\Controller\App;

use App\Common\Service\CategoryServiceInterface;
use App\Core\Controller\RestController;
use App\Core\View\ApiView;
use App\Core\View\DetailApiViewMixin;
use App\Core\View\ListApiViewMixin;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/app/categories', name: 'app-categories-')]
class CategoryController extends RestController
{
    use ApiView, DetailApiViewMixin, ListApiViewMixin;

    public function __construct(
        protected readonly CategoryServiceInterface $service
    ) {}

    /**
     * @return array<string, bool>
     */
    protected function commonFilter()
    {
        return ['enabled' => true];
    }

    protected function detailFilter($filter = null)
    {
        if (is_array($filter)) {
            unset($filter['enabled']);
        }
        return $filter;
    }
}
