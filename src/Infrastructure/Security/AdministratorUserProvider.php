<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

use App\Domain\Administration\Administrator;
use App\Domain\Administration\AdministratorRepository;
use App\Domain\Administration\AdministratorStatus;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/** @implements UserProviderInterface<AdministratorSecurityUser> */
final readonly class AdministratorUserProvider implements UserProviderInterface
{
    public function __construct(private AdministratorRepository $administratorRepository)
    {
    }

    public function loadUserByIdentifier(string $identifier): AdministratorSecurityUser
    {
        $normalizedIdentifier = strtolower(trim($identifier));

        return $this->createSecurityUser(
            $this->administratorRepository->findByLoginId($normalizedIdentifier),
            $normalizedIdentifier,
        );
    }

    public function refreshUser(UserInterface $user): AdministratorSecurityUser
    {
        if (!$user instanceof AdministratorSecurityUser) {
            throw new UnsupportedUserException(sprintf('Unsupported user class "%s".', $user::class));
        }

        return $this->createSecurityUser(
            $this->administratorRepository->findById($user->getId()),
            $user->getUserIdentifier(),
        );
    }

    public function supportsClass(string $class): bool
    {
        return $class === AdministratorSecurityUser::class;
    }

    private function createSecurityUser(?Administrator $administrator, string $identifier): AdministratorSecurityUser
    {
        if ($administrator === null || $administrator->status !== AdministratorStatus::Active) {
            $exception = new UserNotFoundException();
            $exception->setUserIdentifier($identifier);

            throw $exception;
        }

        return AdministratorSecurityUser::fromAdministrator($administrator);
    }
}
