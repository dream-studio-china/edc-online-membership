<?php

namespace App\Common\Service;

use App\Common\Entity\Media;
use App\Core\Service\BaseServiceInterface;
use App\Core\Security\UserUuidPrincipalInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/** @extends BaseServiceInterface<\App\Common\Entity\Media> */
interface MediaServiceInterface extends BaseServiceInterface
{
    /**
     * @param array<string, mixed> $meta
     */
    public function createFromUpload(UploadedFile $file, ?string $storage = null, array $meta = [], ?UserUuidPrincipalInterface $owner = null): Media;
}
