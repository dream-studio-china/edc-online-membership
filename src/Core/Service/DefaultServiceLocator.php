<?php

namespace App\Core\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class DefaultServiceLocator implements ServiceLocatorInterface
{
    private ContainerInterface $container;

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
    }

    public function getEntityManager()
    {
        return $this->container->get('doctrine.orm.entity_manager');
    }

    public function getLogger()
    {
        if ($this->container->has('logger')) {
            return $this->container->get('logger');
        }
        return new \Psr\Log\NullLogger();
    }

    public function getTokenStorage()
    {
        return $this->container->has('security.token_storage') ? $this->container->get('security.token_storage') : null;
    }

    public function getRequestStack()
    {
        return $this->container->has('request_stack') ? $this->container->get('request_stack') : null;
    }

    public function getSerializer()
    {
        try {
            // First try to fetch by the interface id (we alias it in services.yaml).
            return $this->container->get(\Symfony\Component\Serializer\SerializerInterface::class);
        } catch (\Throwable $e) {
            // Fallback to the service id 'serializer' which may exist in some setups.
            try {
                return $this->container->get('serializer');
            } catch (\Throwable $e) {
                return null;
            }
        }
    }

    public function getValidator()
    {
        return $this->container->has('validator') ? $this->container->get('validator') : null;
    }
}
