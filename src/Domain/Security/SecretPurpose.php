<?php

declare(strict_types=1);

namespace App\Domain\Security;

enum SecretPurpose: string
{
    case AdministratorTotpSecret = 'administrator_totp_secret';
    case DiscordWebhookUrl = 'discord_webhook_url';
    case PlatformAccessToken = 'platform_access_token';
}
