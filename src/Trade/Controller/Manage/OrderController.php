<?php

declare(strict_types=1);

namespace App\Trade\Controller\Manage;

use App\Core\Controller\RestController;
use App\Core\Service\BaseService;
use App\Core\View\ApiView;
use App\Core\View\DetailApiViewMixin;
use App\Core\View\ListApiViewMixin;
use App\Trade\Entity\Order;
use App\Trade\Service\OrderServiceInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Exception\ValidatorException;
use Symfony\Component\Workflow\WorkflowInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;

#[Route('/manage/orders', name: 'manage-orders-')]
#[IsGranted('ROLE_ADMIN')]
class OrderController extends RestController
{
    use ApiView, DetailApiViewMixin, ListApiViewMixin;

    public function __construct(
        protected readonly OrderServiceInterface $service,
        #[Target('state_machine.order')]
        protected readonly WorkflowInterface $workflow,
    ) {
    }

    #[Route('', name: 'create', methods: ['POST'])]
    public function createAction(Request $request): Response
    {
        $content = json_decode($request->getContent(), true) ?: [];

        $items = $content['items'] ?? [];
        if (empty($items)) {
            return $this->warning('Items are required.', 400, '', 400);
        }

        $user = isset($content['user']) ? ['id' => (int) $content['user']] : null;
        $currency = $content['currency'] ?? 'CNY';
        $notes = $content['notes'] ?? null;

        try {
            $result = $this->service->calculatePrices($items, $currency);

            $order = $this->service->createOrder(
                $result->items,
                $user,
                $result->totalAmount,
                $currency,
                $notes,
            );

            return $this->success($order, 'SUCCESS', 201);
        } catch (\Throwable $e) {
            return $this->warning($e->getMessage(), 400, '', 400);
        }
    }

    #[Route('/{id<\d+>}', name: 'update', methods: ['PUT'])]
    public function updateAction(Request $request, int $id): Response
    {
        $order = $this->service->get(['id' => $id]);

        if (!$order) {
            return $this->warning('Order not found.', 404, '', 404);
        }

        if ($order->getStatus() !== Order::STATUS_DRAFT) {
            return $this->warning('Only draft orders can be updated.', 400, '', 400);
        }

        $content = json_decode($request->getContent(), true) ?: [];
        $allowed = ['notes', 'metadata'];
        $data = [];
        foreach ($allowed as $prop) {
            if (array_key_exists($prop, $content)) {
                $data[$prop] = $content[$prop];
            }
        }

        return $this->service->update($order, $data)
            ? $this->success($order)
            : $this->warning();
    }

    #[Route('/{id<\d+>}', name: 'delete', methods: ['DELETE'])]
    public function deleteAction(int $id): Response
    {
        $order = $this->service->get(['id' => $id]);

        if (!$order) {
            return $this->warning('Order not found.', 404, '', 404);
        }

        if ($order->getStatus() !== Order::STATUS_DRAFT) {
            return $this->warning('Only draft orders can be deleted.', 400, '', 400);
        }

        return $this->service->remove($order)
            ? $this->success('', 'SUCCESS', 204)
            : $this->warning();
    }

    #[Route('/todo', name: 'todo-list', methods: ['GET'])]
    public function todoAction(): Response
    {
        $entities = BaseService::listResultToCollection(
            $this->service->list(null, null, false)
        )->toArray();

        $entities = array_filter($entities, function ($entity) {
            return count($this->workflow->getEnabledTransitions($entity));
        });

        return $this->success(array_values($entities));
    }

    #[Route('/{id<\d+>}/transitions', name: 'available-transitions', methods: ['GET'])]
    public function transitionsAction(int $id): Response
    {
        $entity = $this->service->get(['id' => $id]);

        if (!$entity) {
            return $this->warning('Order not found.', 404, '', 404);
        }

        $transitions = $this->workflow->getEnabledTransitions($entity);

        return $this->success($transitions);
    }

    #[Route('/{id<\d+>}/do/{transition}', name: 'do-transition', methods: ['POST'])]
    public function doTransitionAction(Request $request, int $id, string $transition): Response
    {
        try {
            $entity = $this->service->get(['id' => $id]);

            if (!$entity) {
                return $this->warning('Order not found.', 404, '', 404);
            }

            if (!$this->workflow->can($entity, $transition)) {
                throw new ValidatorException('Current transition cannot be applied.');
            }

            $content = json_decode($request->getContent(), true);

            $this->service->wrapInTransaction(function ($em) use ($entity, $content, $transition) {
                if ($content) {
                    $this->service->update($entity, $content);
                }
                $this->workflow->apply($entity, $transition);
            });

        } catch (\Throwable $e) {
            return $this->warning($e->getMessage());
        }

        return $this->success();
    }
}
