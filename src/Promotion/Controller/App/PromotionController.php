<?php /** @noinspection PhpMissingParentConstructorInspection */

declare(strict_types=1);

namespace App\Promotion\Controller\App;

use App\Core\Controller\RestController;
use App\Core\View\ApiView;
use App\Core\View\DetailApiViewMixin;
use App\Core\View\ListApiViewMixin;
use App\Promotion\Entity\Promotion;
use App\Promotion\Service\PromotionServiceInterface;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/app/promotions', name: 'app-promotions-')]
class PromotionController extends RestController
{
    use ApiView, DetailApiViewMixin, ListApiViewMixin;

    public function __construct(
        protected readonly PromotionServiceInterface $service,
    ) {}

    protected function commonFilter(): array
    {
        $filter = ['enabled' => true];

        $now = new \DateTimeImmutable();

        // Active time-window filtering is handled in getAvailable()
        return $filter;
    }
}
