<?php

declare(strict_types=1);

namespace App\Liansheng\Controller\App;

use App\Core\Controller\RestController;
use App\Liansheng\Exception\LianshengApiException;
use App\Liansheng\Service\LianshengServiceInterface;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/app/liansheng', name: 'app-liansheng-')]
#[IsGranted('ROLE_USER')]
final class LianshengController extends RestController
{
    public function __construct(private readonly LianshengServiceInterface $lianshengService)
    {
    }

    #[OA\Get(
        path: '/api/v1/app/liansheng/store',
        summary: 'Get Liansheng store information',
        responses: [
            new OA\Response(response: 200, description: 'Store information returned'),
            new OA\Response(response: 502, description: 'Liansheng API failure'),
        ],
        tags: ['Liansheng'],
    )]
    #[Route('/store', name: 'store', methods: ['GET'])]
    public function store(): Response
    {
        try {
            return $this->externalSuccess($this->lianshengService->getStore());
        } catch (LianshengApiException $exception) {
            return $this->warning($exception->getMessage(), 1, null, Response::HTTP_BAD_GATEWAY);
        }
    }

    #[OA\Get(
        path: '/api/v1/app/liansheng/member',
        summary: 'Get Liansheng member profiles by mobile number',
        parameters: [
            new OA\Parameter(
                name: 'mobile',
                in: 'query',
                required: true,
                schema: new OA\Schema(type: 'string'),
                example: '13802542123',
            ),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Member profile returned'),
            new OA\Response(response: 400, description: 'Mobile number missing'),
            new OA\Response(response: 502, description: 'Liansheng API failure'),
        ],
        tags: ['Liansheng'],
    )]
    #[Route('/member', name: 'member', methods: ['GET'])]
    public function member(Request $request): Response
    {
        $mobile = trim((string) $request->query->get('mobile', ''));
        if ($mobile === '') {
            return $this->warning('mobile is required.', 1, null, Response::HTTP_BAD_REQUEST);
        }

        try {
            return $this->externalSuccess($this->lianshengService->getMemberByMobile($mobile));
        } catch (LianshengApiException $exception) {
            return $this->warning($exception->getMessage(), 1, null, Response::HTTP_BAD_GATEWAY);
        }
    }

    /** @param array<int|string, mixed> $data */
    private function externalSuccess(array $data): JsonResponse
    {
        return new JsonResponse(['data' => $data, 'code' => 0, 'message' => 'SUCCESS']);
    }
}
