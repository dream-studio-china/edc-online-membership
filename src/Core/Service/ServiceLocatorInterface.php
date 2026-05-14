<?php

namespace App\Core\Service;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

interface ServiceLocatorInterface
{
    // Return types intentionally omitted to keep test fakes lightweight and avoid requiring full framework
    // implementations in unit tests. Production locators can still return the concrete interfaces.
    public function getEntityManager();
    public function getLogger();
    public function getTokenStorage();
    public function getRequestStack();
    public function getSerializer();
    public function getValidator();
}
