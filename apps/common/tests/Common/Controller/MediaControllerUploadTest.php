<?php

declare(strict_types=1);

namespace App\Tests\Common\Controller;

use App\Common\Controller\App\MediaController as AppMediaController;
use App\Common\Controller\Manage\MediaController as ManageMediaController;
use App\Common\Entity\Media;
use App\Common\Service\MediaService;
use App\Common\Service\MediaServiceInterface;
use App\Core\Security\UserUuidPrincipalInterface;
use App\Core\Security\UserUuidResolverInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
use Symfony\Contracts\Translation\TranslatorInterface;

final class MediaControllerUploadTest extends TestCase
{
    private string $tempRoot;

    protected function setUp(): void
    {
        $this->tempRoot = sys_get_temp_dir() . '/crud-media-controller-test-' . bin2hex(random_bytes(4));
        mkdir($this->tempRoot, 0775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tempRoot . '/*') ?: [] as $file) {
            unlink($file);
        }

        if (is_dir($this->tempRoot)) {
            rmdir($this->tempRoot);
        }
    }

    public function testAppUploadReturns500ForUnexpectedError(): void
    {
        $service = new class implements MediaServiceInterface {
            public function createFromUpload(UploadedFile $file, ?string $storage = null, array $meta = [], ?UserUuidPrincipalInterface $owner = null): Media
            {
                throw new \LogicException('unexpected app failure');
            }

            public function get($object, bool $directly = false) { return null; }
            public function list($object = null, $order = null, bool $disableRequest = true) { return []; }
            public function new() { return new \stdClass(); }
            public function update($object, ?array $data = null, bool $noFlush = false) { return $object; }
            public function remove($object): bool { return false; }
        };

        $controller = new class($service) extends AppMediaController {
            protected function uploadOwner(): ?UserUuidPrincipalInterface
            {
                return null;
            }
        };
        $this->configureController($controller);

        $response = $controller->uploadAction($this->requestWithFile());
        $data = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(500, $response->getStatusCode());
        self::assertSame('unexpected app failure', $data['message']);
    }

    public function testManageUploadReturns500ForUnexpectedError(): void
    {
        $service = new class extends MediaService {
            public function __construct() {}

        public function createFromUpload(UploadedFile $file, ?string $storage = null, array $meta = [], ?UserUuidPrincipalInterface $owner = null): Media
        {
            throw new \LogicException('unexpected manage failure');
        }
        };

        $controller = new ManageMediaController($service, $this->createMock(UserUuidResolverInterface::class));
        $this->configureController($controller);

        $response = $controller->uploadAction($this->requestWithFile());
        $data = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(500, $response->getStatusCode());
        self::assertSame('unexpected manage failure', $data['message']);
    }

    private function configureController(object $controller): void
    {
        $serializer = new Serializer([new ObjectNormalizer()], [new JsonEncoder()]);
        $translator = new class implements TranslatorInterface {
            public function trans(?string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
            {
                return strtr((string) $id, $parameters);
            }

            public function getLocale(): string
            {
                return 'en';
            }
        };

        $controller->setSerializer($serializer);
        $controller->setTranslator($translator);
    }

    private function requestWithFile(): Request
    {
        $path = $this->tempRoot . '/upload.png';
        file_put_contents($path, 'png');

        return new Request(files: ['file' => new UploadedFile($path, 'upload.png', 'image/png', null, true)]);
    }
}
