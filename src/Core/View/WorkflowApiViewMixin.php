<?php

namespace App\Core\View;

use App\Core\Service\BaseService;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Exception\ValidatorException;

trait WorkflowApiViewMixin
{
    // protected $workflow;

    #[OA\Get(
        tags: ['Workflow'],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'limit', in: 'query', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Todo list'),
        ]
    )]
    #[Route('/todo', name: 'todo-list', methods: ['GET'])]
    public function todoAction()
    {
        $service = $this->service ?? $this->get($this->serviceClass);
        $entities = BaseService::listResultToCollection(
            $service->list(null, null, false)
        )->toArray();

        // TODO: this method will VERY SLOW when reached the large apply entry.
        $entities = array_filter($entities, function ($entity) {
            $workflow = $this->get($this->workflow);
            return count($workflow->getEnabledTransitions($entity));
        });

        return $this->success($entities);
    }

    #[OA\Get(
        tags: ['Workflow'],
        responses: [
            new OA\Response(response: 200, description: 'List enabled transitions'),
        ]
    )]
    #[Route('/{id}/transitions', name: 'available-transition', methods: ['GET'])]
    public function availableTransitionsAction($id)
    {
        $service = $this->service ?? $this->get($this->serviceClass);
        $entity = $service->get(['id' => $id]);

        $workflow = $this->get($this->workflow);
        $transitions = $workflow->getEnabledTransitions($entity);

        return $this->success($transitions);
    }

    #[OA\Post(
        tags: ['Workflow'],
        responses: [
            new OA\Response(response: 200, description: 'Do transition'),
        ]
    )]
    #[Route('/{id}/do/{transition}', name: 'do-transition', methods: ['POST'])]
    public function doTransitionAction(Request $request, $id, $transition)
    {
        // TODO DANGER: This endpoint will potentially modify the entity and change its workflow state.
        // It calls the service->update() (if request body provided) and then workflow->apply(),
        // persisting changes immediately. This means:
        //  - Side effects and business logic may execute (DB writes, downstream processing).
        //  - Concurrent calls can cause race conditions or inconsistent state if not guarded (use locking or transactions).
        //  - Input is applied directly to the entity; ensure strict validation and do not accept untrusted fields.
        //  - Permissions/guards must be enforced (workflow guards and controller-level security may not be sufficient in all cases).
        //  - Consider adding audit logging, limiting allowed transitions, requiring explicit confirmation, or moving heavy work to background jobs.

        try {
            $service = $this->service ?? $this->get($this->serviceClass);
            $entity = $service->get(['id' => $id]);
            $workflow = $this->get($this->workflow);

            if($workflow->can($entity, $transition)) {
                $content = json_decode($request->getContent(), true);
                if($content) {
                    $service->update($entity, $content);
                }

                $workflow->apply($entity, $transition);
                $this->get('doctrine')->getManager()->flush();
            }
            else {
                throw new ValidatorException('Current transition cannot be applied.');
            }

        } catch (\Throwable $e) {
            return $this->warning($e->getMessage());
        }

        return $this->success();
    }

    #[OA\Put(
        tags: ['Workflow'],
        responses: [
            new OA\Response(response: 200, description: 'Reset marking'),
        ]
    )]
    #[IsGranted('ROLE_ADMIN')]
    #[Route('/{id}/status-reset', name: 'reset-status', methods: ['PUT'])]
    public function resetMarkingAction($entity)
    {
        $entity->setStatus([]);
        $this->get('doctrine')->getManager()->flush();

        return $this->success();
    }
}
