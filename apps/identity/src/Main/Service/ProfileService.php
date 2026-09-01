<?php

declare(strict_types=1);

namespace App\Identity\Main\Service;

use App\Core\Service\BaseService;
use App\Identity\Main\Entity\Profile;
use App\Identity\Main\Entity\User;
use Symfony\Component\DependencyInjection\ContainerInterface;

/** @extends BaseService<\App\Identity\Main\Entity\Profile> */
class ProfileService extends BaseService implements ProfileServiceInterface
{
    public function __construct(ContainerInterface $container)
    {
        parent::__construct($container, Profile::class);
    }

    public function joinAsMember(User $user): Profile
    {
        /** @var Profile|null $existing */
        $existing = $this->rep->findOneBy(['user' => $user]);
        if ($existing !== null) {
            return $existing;
        }

        $profile = new Profile($user, Profile::LEVEL_BRONZE);

        $this->em->persist($profile);
        $this->em->flush();

        return $profile;
    }
}
