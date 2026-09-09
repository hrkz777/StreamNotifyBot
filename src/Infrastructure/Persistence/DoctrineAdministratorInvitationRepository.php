<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Administration\Administrator;
use App\Domain\Administration\AdministratorInvitationRepository;
use App\Domain\Administration\AdministratorStatus;
use App\Domain\Administration\AdministratorToken;
use App\Domain\Administration\AdministratorTokenPurpose;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use InvalidArgumentException;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineAdministratorInvitationRepository implements AdministratorInvitationRepository
{
    public function __construct(private Connection $connection)
    {
    }

    public function create(Administrator $administrator, AdministratorToken $token): void
    {
        if (
            $administrator->status !== AdministratorStatus::Pending
            || $administrator->passwordHash !== null
            || $token->purpose !== AdministratorTokenPurpose::Invitation
            || $token->administratorId !== $administrator->id
            || $token->authenticationVersion !== $administrator->authenticationVersion
        ) {
            throw new InvalidArgumentException('管理者招待の集約状態が不正です。');
        }

        $this->connection->transactional(function (Connection $connection) use ($administrator, $token): void {
            $connection->insert('administrators', [
                'id' => Uuid::fromString($administrator->id)->toBinary(), 'login_id' => $administrator->loginId,
                'display_name' => $administrator->displayName, 'role' => $administrator->role->value,
                'status' => $administrator->status->value, 'password_hash' => null,
                'authentication_version' => $administrator->authenticationVersion, 'password_changed_at' => null,
                'totp_enrolled_at' => null, 'last_login_at' => null, 'disabled_at' => null, 'deleted_at' => null,
                'created_at' => self::date($administrator->createdAt), 'updated_at' => self::date($administrator->updatedAt), 'lock_version' => $administrator->lockVersion,
            ], ['id' => ParameterType::BINARY]);
            $hash = hex2bin($token->tokenHash);
            if ($hash === false) {
                throw new InvalidArgumentException('トークンハッシュを変換できません。');
            }
            $connection->insert('administrator_tokens', [
                'id' => Uuid::fromString($token->id)->toBinary(), 'administrator_id' => Uuid::fromString($administrator->id)->toBinary(),
                'purpose' => $token->purpose->value, 'token_hash' => $hash,
                'created_by_administrator_id' => $token->createdByAdministratorId === null ? null : Uuid::fromString($token->createdByAdministratorId)->toBinary(),
                'authentication_version' => $token->authenticationVersion, 'created_at' => self::date($token->createdAt),
                'expires_at' => self::date($token->expiresAt), 'consumed_at' => null, 'revoked_at' => null,
            ], ['id' => ParameterType::BINARY, 'administrator_id' => ParameterType::BINARY, 'token_hash' => ParameterType::BINARY, 'created_by_administrator_id' => ParameterType::BINARY]);
        });
    }

    private static function date(DateTimeImmutable $dateTime): string
    {
        return $dateTime->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
}
