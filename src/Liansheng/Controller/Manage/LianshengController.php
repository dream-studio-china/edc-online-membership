<?php

declare(strict_types=1);

namespace App\Liansheng\Controller\Manage;

use App\Core\Controller\RestController;
use App\Liansheng\Exception\LianshengApiException;
use App\Liansheng\Service\LianshengServiceInterface;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/manage/liansheng', name: 'manage-liansheng-')]
#[IsGranted('ROLE_ADMIN')]
final class LianshengController extends RestController
{
    public function __construct(private readonly LianshengServiceInterface $lianshengService)
    {
    }

    #[OA\Get(
        path: '/api/v1/manage/liansheng/business-revenue',
        summary: 'Get a Liansheng business revenue report',
        parameters: [
            new OA\Parameter(name: 'beginDate', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date'), example: '2025-02-10'),
            new OA\Parameter(name: 'endDate', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date'), example: '2025-02-10'),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Business revenue report returned'),
            new OA\Response(response: 400, description: 'Invalid date range'),
            new OA\Response(response: 502, description: 'Liansheng API failure'),
        ],
        tags: ['Liansheng'],
    )]
    #[Route('/business-revenue', name: 'business-revenue', methods: ['GET'])]
    public function businessRevenue(Request $request): Response
    {
        $beginDate = $this->parseDate((string) $request->query->get('beginDate', ''));
        $endDate = $this->parseDate((string) $request->query->get('endDate', ''));
        if ($beginDate === null || $endDate === null) {
            return $this->warning('beginDate and endDate must use YYYY-MM-DD.', 1, null, Response::HTTP_BAD_REQUEST);
        }
        if ($beginDate > $endDate) {
            return $this->warning('beginDate must not be after endDate.', 1, null, Response::HTTP_BAD_REQUEST);
        }

        try {
            return $this->externalSuccess($this->lianshengService->getBusinessRevenueReport($beginDate, $endDate));
        } catch (LianshengApiException $exception) {
            return $this->warning($exception->getMessage(), 1, null, Response::HTTP_BAD_GATEWAY);
        }
    }

    private function parseDate(string $value): ?\DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = \DateTimeImmutable::getLastErrors();

        if ($date === false || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            return null;
        }

        return $date->format('Y-m-d') === $value ? $date : null;
    }

    /** @param array<string, mixed> $data */
    private function externalSuccess(array $data): JsonResponse
    {
        return new JsonResponse(['data' => $data, 'code' => 0, 'message' => 'SUCCESS']);
    }
}
