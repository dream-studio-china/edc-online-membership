<?php

declare(strict_types=1);

namespace App\Inventory\Controller\Manage;

use App\Core\Controller\RestController;
use App\Inventory\Entity\RecipeLine;
use App\Inventory\Entity\SpecificationRecipe;
use App\Inventory\Repository\MaterialRepository;
use App\Inventory\Repository\SpecificationRecipeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/manage/inventory/recipes', name: 'manage-inventory-recipes-')]
#[IsGranted('ROLE_ADMIN')]
final class RecipeController extends RestController
{
    public function __construct(
        private readonly SpecificationRecipeRepository $recipes,
        private readonly MaterialRepository $materials,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('', name: 'list', methods: ['GET'])]
    public function listAction(): Response
    {
        return $this->success($this->recipes->findBy([], ['createdAt' => 'DESC']));
    }

    #[Route('', name: 'create', methods: ['POST'])]
    public function createAction(Request $request): Response
    {
        $data = json_decode($request->getContent(), true);
        if (
            !is_array($data)
            || !is_string($data['specificationUuid'] ?? null)
            || !is_array($data['lines'] ?? null)
            || $data['lines'] === []
        ) {
            return $this->warning('specificationUuid and non-empty lines are required.', 400, '', 400);
        }
        try {
            if ($this->recipes->findOneBy(['specificationUuid' => $data['specificationUuid']]) !== null) {
                throw new \LogicException('A recipe already exists for this specification.');
            }
            $recipe = new SpecificationRecipe($data['specificationUuid']);
            foreach ($data['lines'] as $index => $line) {
                if (
                    !is_array($line)
                    || !is_string($line['materialUuid'] ?? null)
                    || !is_string($line['quantityPerUnit'] ?? null)
                ) {
                    throw new \InvalidArgumentException('Each recipe line requires materialUuid and quantityPerUnit.');
                }
                $material = $this->materials->findOneByUuid($line['materialUuid']);
                if ($material === null || !$material->isActive()) {
                    throw new \InvalidArgumentException('Recipe material was not found or is inactive.');
                }
                $recipe->addLine(new RecipeLine(
                    $material,
                    $line['quantityPerUnit'],
                    is_int($line['sort'] ?? null) ? $line['sort'] : $index,
                ));
            }
            $this->entityManager->persist($recipe);
            $this->entityManager->flush();

            return $this->success($recipe, 'Success', 201);
        } catch (\Throwable $exception) {
            return $this->warning($exception->getMessage(), 400, '', 400);
        }
    }
}
