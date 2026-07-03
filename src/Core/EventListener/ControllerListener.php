<?php

namespace App\Core\EventListener;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Doctrine\ORM\Events;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

#[AsDoctrineListener(Events::loadClassMetadata)]
class ControllerListener
{
    /** @var ContainerInterface */
    private $container;
    /** @var TokenStorageInterface */
    private $tokenStorage;
    /** @var LoggerInterface */
    private $logger;

    private const QUOTED_COLUMNS = [
        'App\\Common\\Entity\\Setting' => ['key', 'value'],
    ];

    public function __construct(ContainerInterface $container, TokenStorageInterface $tokenStorage, LoggerInterface $logger)
    {
        $this->container = $container;
        $this->tokenStorage = $tokenStorage;
        $this->logger = $logger;
    }

    public function onKernelController(ControllerEvent $event): void
    {
        // get operation user
        if ($this->tokenStorage->getToken()) {
            $operator = $this->tokenStorage->getToken()->getUser();
            if (is_object($operator) && method_exists($operator, 'getId')) {
                $operator = $operator->getId();
            }
        }
        else return;

        $controller = $event->getController();
        $request = $event->getRequest();

        $method = $request->getMethod();
        $uri = $request->getRequestUri();

        $content = $request->getContent();
        if(strlen($content) > 1024 /* 1K */) {
            $content = '...';
        }

        if(preg_match("/(PUT|POST)/i", $method)) {
            $this->logger->info(
                "User [#$operator] Requests $method $uri: $content"
            );
        }
    }

    public function loadClassMetadata(LoadClassMetadataEventArgs $args): void
    {
        $classMetadata = $args->getClassMetadata();
        $className = $classMetadata->getName();

        if (!isset(self::QUOTED_COLUMNS[$className])) {
            return;
        }

        foreach (self::QUOTED_COLUMNS[$className] as $fieldName) {
            if (isset($classMetadata->fieldMappings[$fieldName])) {
                $classMetadata->fieldMappings[$fieldName]->quoted = true;
            }
        }
    }
}
