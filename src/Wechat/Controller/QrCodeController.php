<?php

declare(strict_types=1);

namespace App\Wechat\Controller;

use App\Wechat\Service\WechatServiceInterface;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/api/wechat', name: 'wechat-')]
class QrCodeController extends AbstractController
{
    public function __construct(
        private readonly WechatServiceInterface $wechatService,
        private readonly TranslatorInterface $translator,
    ) {}

    #[OA\Get(
        path: '/api/wechat/miniapp/qrcode',
        summary: 'Mini Program — generate unlimited QR code',
        description: 'Calls getwxacodeunlimit with scene/page/width and returns raw PNG bytes.',
        parameters: [
            new OA\Parameter(name: 'scene', in: 'query', required: true, schema: new OA\Schema(type: 'string', maxLength: 32), description: 'Scene string, max 32 chars', example: 'id=123'),
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'string', default: 'pages/index/index'), description: 'Mini Program page', example: 'pages/index/index'),
            new OA\Parameter(name: 'width', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 430, minimum: 280, maximum: 1280), description: 'Image width in px', example: 430),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'PNG image bytes',
                content: new OA\MediaType(
                    mediaType: 'image/png',
                    schema: new OA\Schema(type: 'string', format: 'binary')
                )
            ),
            new OA\Response(response: 400, description: 'Missing/invalid scene, page or width'),
            new OA\Response(response: 502, description: 'WeChat API error'),
        ],
        tags: ['Wechat']
    )]
    #[Route('/miniapp/qrcode', name: 'miniapp-qrcode', methods: ['GET'])]
    public function miniappQrcode(Request $request): Response
    {
        $scene = trim((string) $request->query->get('scene', ''));
        $page = trim((string) $request->query->get('page', 'pages/index/index'));
        $width = (int) $request->query->get('width', 430);

        if ($scene === '') {
            return $this->error('scene is required.', Response::HTTP_BAD_REQUEST);
        }

        if (\strlen($scene) > 32) {
            return $this->error('scene must not exceed 32 characters.', Response::HTTP_BAD_REQUEST);
        }

        if ($page === '') {
            $page = 'pages/index/index';
        }

        if ($width < 280 || $width > 1280) {
            return $this->error('width must be between 280 and 1280.', Response::HTTP_BAD_REQUEST);
        }

        try {
            $image = $this->wechatService->getMiniProgramUnlimitedCode($scene, $page, $width);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), Response::HTTP_BAD_REQUEST);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), Response::HTTP_BAD_GATEWAY);
        }

        return new Response($image, Response::HTTP_OK, [
            'Content-Type' => 'image/png',
            'Content-Disposition' => 'inline; filename="qrcode.png"',
        ]);
    }

    private function error(string $message, int $status = Response::HTTP_BAD_REQUEST): JsonResponse
    {
        return new JsonResponse([
            'code' => $status,
            'message' => $this->translator->trans($message),
        ], $status);
    }
}
