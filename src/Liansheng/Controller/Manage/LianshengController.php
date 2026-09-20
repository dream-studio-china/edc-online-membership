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
            new OA\Parameter(name: 'X-Store-Code', in: 'header', required: true, schema: new OA\Schema(type: 'string'), example: 'LIANSHENG-TEST'),
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

    #[OA\Post(
        path: '/api/v1/manage/liansheng/member-points',
        summary: 'Adjust Liansheng member points through 0703',
        parameters: [
            new OA\Parameter(name: 'X-Store-Code', in: 'header', required: true, schema: new OA\Schema(type: 'string'), example: 'LIANSHENG-TEST'),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['mobile', 'dirflag', 'points', 'reference', 'roomtable', 'remarks'],
                properties: [
                    new OA\Property(property: 'mobile', type: 'string', example: '13802542123'),
                    new OA\Property(property: 'dirflag', type: 'string', enum: ['-', '+'], description: '- deducts points; + credits points', example: '-'),
                    new OA\Property(property: 'points', type: 'integer', minimum: 1, example: 10),
                    new OA\Property(property: 'reference', type: 'string', description: 'Stable, unique provider reference', example: 'MANUAL-POINTS-001'),
                    new OA\Property(property: 'roomtable', type: 'string', example: 'ONLINE'),
                    new OA\Property(property: 'remarks', type: 'string', example: 'Manual adjustment'),
                    new OA\Property(property: 'accountDate', type: 'string', format: 'date', example: '2026-09-20'),
                    new OA\Property(property: 'expiryDate', type: 'string', format: 'date', description: 'Optional for credits; Store default applies when omitted', example: '2099-12-31'),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 200, description: 'Points adjusted'),
            new OA\Response(response: 400, description: 'Invalid adjustment request'),
            new OA\Response(response: 502, description: 'Liansheng API failure'),
        ],
        tags: ['Liansheng'],
    )]
    #[Route('/member-points', name: 'member-points', methods: ['POST'])]
    public function memberPoints(Request $request): Response
    {
        try {
            $content = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->warning('Invalid JSON.', 1, null, Response::HTTP_BAD_REQUEST);
        }
        if (!is_array($content)) {
            return $this->warning('Invalid JSON.', 1, null, Response::HTTP_BAD_REQUEST);
        }

        $mobile = $content['mobile'] ?? null;
        $dirflag = $content['dirflag'] ?? null;
        $reference = $content['reference'] ?? null;
        $roomTable = $content['roomtable'] ?? null;
        $remarks = $content['remarks'] ?? null;
        $points = $content['points'] ?? null;
        if (!is_string($mobile) || !is_string($dirflag) || !is_string($reference) || !is_string($roomTable) || !is_string($remarks)
            || trim($mobile) === '' || !in_array($dirflag, ['-', '+'], true) || trim($reference) === '' || trim($roomTable) === '' || trim($remarks) === '') {
            return $this->warning('mobile, dirflag, reference, roomtable, and remarks are required.', 1, null, Response::HTTP_BAD_REQUEST);
        }
        if ((!is_int($points) && !(is_string($points) && ctype_digit($points))) || (int) $points <= 0) {
            return $this->warning('points must be a positive integer.', 1, null, Response::HTTP_BAD_REQUEST);
        }

        $accountDateValue = $content['accountDate'] ?? null;
        $expiryDateValue = $content['expiryDate'] ?? null;
        if (($accountDateValue !== null && !is_string($accountDateValue)) || ($expiryDateValue !== null && !is_string($expiryDateValue))) {
            return $this->warning('accountDate and expiryDate must use YYYY-MM-DD.', 1, null, Response::HTTP_BAD_REQUEST);
        }
        $accountDate = $this->optionalDate($accountDateValue);
        $expiryDate = $this->optionalDate($expiryDateValue);
        if (($accountDateValue !== null && $accountDate === null) || ($expiryDateValue !== null && $expiryDate === null)) {
            return $this->warning('accountDate and expiryDate must use YYYY-MM-DD.', 1, null, Response::HTTP_BAD_REQUEST);
        }

        try {
            $data = $dirflag === '-'
                ? $this->lianshengService->deductMemberPoints(trim($mobile), (int) $points, trim($reference), trim($remarks), $accountDate, trim($roomTable))
                : $this->lianshengService->creditMemberPoints(trim($mobile), (int) $points, trim($reference), trim($remarks), $accountDate, $expiryDate, trim($roomTable));

            return $this->externalSuccess($data);
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

    private function optionalDate(?string $value): ?\DateTimeImmutable
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return $this->parseDate($value);
    }

    /** @param array<string, mixed> $data */
    private function externalSuccess(array $data): JsonResponse
    {
        return new JsonResponse(['data' => $data, 'code' => 0, 'message' => 'SUCCESS']);
    }
}
