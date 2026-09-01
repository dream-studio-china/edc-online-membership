<?php

declare(strict_types=1);

namespace App\Core\View;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

trait ScopedDetailApiViewMixin
{
    /** @return array<string, mixed>|\Doctrine\ORM\QueryBuilder */
    abstract protected function scopedDetailFilter(string $scopeId, string $id): array|\Doctrine\ORM\QueryBuilder;

    #[Route('/{id}', name: 'detail', requirements: ['id' => '\\d+|[0-9a-fA-F-]{36}'], methods: ['GET'])]
    public function detailAction(string $scopeId, string $id): Response
    {
        $entity = $this->service->get($this->scopedDetailFilter($scopeId, $id), false);

        return $entity === null
            ? $this->warning(ApiViewMessages::ENTITY_NOT_FOUND, 404, '', 404)
            : $this->success($entity);
    }
}
