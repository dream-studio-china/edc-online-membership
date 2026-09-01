<?php

namespace App\Tests\Core\View;

use App\Core\View\WorkflowApiViewMixin;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class WorkflowApiViewMixinTest extends TestCase
{
    public function testTodoAndAvailableTransitionsOnlyReturnActionableEntities(): void
    {
        $first = new WorkflowTestEntity(1);
        $second = new WorkflowTestEntity(2);
        $workflow = new WorkflowTestWorkflow([1 => ['approve'], 2 => []]);
        $service = new WorkflowTestService([$first, $second]);
        $view = new WorkflowTestView($service, $workflow);

        self::assertSame([$first], $view->todoAction()['data']);
        self::assertSame(['approve'], $view->availableTransitionsAction(1)['data']);
    }

    public function testTransitionUpdatesPayloadAndReturnsWarningWhenUnavailable(): void
    {
        $entity = new WorkflowTestEntity(1);
        $workflow = new WorkflowTestWorkflow([1 => ['approve']]);
        $service = new WorkflowTestService([$entity]);
        $view = new WorkflowTestView($service, $workflow);

        self::assertSame(['status' => 'ok'], $view->doTransitionAction(new Request(content: '{"note":"approved"}'), 1, 'approve'));
        self::assertSame(['note' => 'approved'], $entity->updates);
        self::assertSame([[1, 'approve']], $workflow->applied);
        self::assertSame(['warning' => 'Current transition cannot be applied.'], $view->doTransitionAction(new Request(), 1, 'reject'));
    }

    public function testResetMarkingFlushesTheDoctrineManager(): void
    {
        $entity = new WorkflowTestEntity(1);
        $manager = new class { public bool $flushed = false; public function flush(): void { $this->flushed = true; } };
        $view = new WorkflowTestView(new WorkflowTestService([$entity]), new WorkflowTestWorkflow([]), $manager);

        self::assertSame(['status' => 'ok'], $view->resetMarkingAction($entity));
        self::assertSame([], $entity->status);
        self::assertTrue($manager->flushed);
    }
}

final class WorkflowTestView
{
    use WorkflowApiViewMixin;

    public object $container;
    public object $service;
    public string $serviceClass = 'workflow_service';
    public string $workflow = 'workflow';

    public function __construct(object $service, object $workflow, ?object $manager = null)
    {
        $this->service = $service;
        $this->container = new class($workflow, $manager) {
            public function __construct(private object $workflow, private ?object $manager) {}
            public function get(string $id): object
            {
                return $id === 'workflow' ? $this->workflow : new class($this->manager) {
                    public function __construct(private ?object $manager) {}
                    public function getManager(): object { return $this->manager ?? new class { public function flush(): void {} }; }
                };
            }
        };
    }

    public function success(mixed $data = null): array { return $data === null ? ['status' => 'ok'] : ['data' => $data]; }
    public function warning(string $message): array { return ['warning' => $message]; }
}

final class WorkflowTestService
{
    public function __construct(private array $entities) {}
    public function list(): array { return $this->entities; }
    public function get(array $criteria): WorkflowTestEntity { return $this->entities[$criteria['id'] - 1]; }
    public function update(WorkflowTestEntity $entity, array $content): void { $entity->updates = $content; }
    public function wrapInTransaction(callable $callback): void { $callback(new \stdClass()); }
}

final class WorkflowTestWorkflow
{
    public array $applied = [];
    public function __construct(private array $transitions) {}
    public function getEnabledTransitions(WorkflowTestEntity $entity): array { return $this->transitions[$entity->id] ?? []; }
    public function can(WorkflowTestEntity $entity, string $transition): bool { return in_array($transition, $this->getEnabledTransitions($entity), true); }
    public function apply(WorkflowTestEntity $entity, string $transition): void { $this->applied[] = [$entity->id, $transition]; }
}

final class WorkflowTestEntity
{
    public array $updates = [];
    public array $status = ['pending'];
    public function __construct(public int $id) {}
    public function setStatus(array $status): void { $this->status = $status; }
}
