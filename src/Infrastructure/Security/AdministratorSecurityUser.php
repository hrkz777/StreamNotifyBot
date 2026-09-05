<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

use App\Domain\Administration\Administrator;
use App\Domain\Administration\AdministratorRole;
use Symfony\Component\Security\Core\User\EquatableInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

final class AdministratorSecurityUser implements UserInterface, PasswordAuthenticatedUserInterface, EquatableInterface
{
    /** @param list<string> $roles */
    private function __construct(
        private string $id,
        private string $loginId,
        private string $displayName,
        private array $roles,
        private int $authenticationVersion,
        private ?string $passwordHash,
    ) {
    }

    public static function fromAdministrator(Administrator $administrator): self
    {
        $roles = match ($administrator->role) {
            AdministratorRole::Owner => ['ROLE_OWNER', 'ROLE_ADMINISTRATOR'],
            AdministratorRole::Administrator => ['ROLE_ADMINISTRATOR'],
        };

        return new self(
            $administrator->id,
            $administrator->loginId,
            $administrator->displayName,
            $roles,
            $administrator->authenticationVersion,
            $administrator->passwordHash,
        );
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getDisplayName(): string
    {
        return $this->displayName;
    }

    public function getAuthenticationVersion(): int
    {
        return $this->authenticationVersion;
    }

    /** @return list<string> */
    public function getRoles(): array
    {
        return $this->roles;
    }

    public function getPassword(): ?string
    {
        return $this->passwordHash;
    }

    public function getUserIdentifier(): string
    {
        assert($this->loginId !== '');

        return $this->loginId;
    }

    public function eraseCredentials(): void
    {
    }

    public function isEqualTo(UserInterface $user): bool
    {
        return $user instanceof self
            && $this->id === $user->id
            && $this->authenticationVersion === $user->authenticationVersion
            && $this->roles === $user->roles;
    }

    /** @return array{id: string, loginId: string, displayName: string, roles: list<string>, authenticationVersion: int} */
    public function __serialize(): array
    {
        return [
            'id' => $this->id,
            'loginId' => $this->loginId,
            'displayName' => $this->displayName,
            'roles' => $this->roles,
            'authenticationVersion' => $this->authenticationVersion,
        ];
    }

    /** @param array{id: string, loginId: string, displayName: string, roles: list<string>, authenticationVersion: int} $data */
    public function __unserialize(array $data): void
    {
        $this->id = $data['id'];
        $this->loginId = $data['loginId'];
        $this->displayName = $data['displayName'];
        $this->roles = $data['roles'];
        $this->authenticationVersion = $data['authenticationVersion'];
        $this->passwordHash = null;
    }
}
