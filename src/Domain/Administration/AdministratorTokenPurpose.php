<?php

declare(strict_types=1);

namespace App\Domain\Administration;

enum AdministratorTokenPurpose: string
{
    case InitialSetup = 'initial_setup';
    case Invitation = 'invitation';
    case CredentialReset = 'credential_reset';
}
