<?php

namespace App\Core\Service;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Return types intentionally omitted from the signature to keep
 * test fakes lightweight (no need to implement full framework interfaces).
 * Production locators (DefaultServiceLocator) add native return types.
 * See phpstan.neon for per-method exclusions.
 */
interface ServiceLocatorInterface
{
    /**
     * @phpstan-return EntityManagerInterface
     */
    public function getEntityManager();

    /**
     * @phpstan-return LoggerInterface
     */
    public function getLogger();

    /**
     * @phpstan-return TokenStorageInterface|null
     */
    public function getTokenStorage();

    /**
     * @phpstan-return RequestStack|null
     */
    public function getRequestStack();

    /**
     * @phpstan-return SerializerInterface|null
     */
    public function getSerializer();

    /**
     * @phpstan-return ValidatorInterface|null
     */
    public function getValidator();
}
