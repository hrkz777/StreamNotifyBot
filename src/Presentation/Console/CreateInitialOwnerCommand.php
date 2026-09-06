<?php

declare(strict_types=1);

namespace App\Presentation\Console;

use App\Application\Administration\BeginAdministratorTotpEnrollment;
use App\Application\Administration\CreateInitialOwner;
use App\Domain\Administration\AdministratorPasswordRejected;
use App\Domain\Administration\InitialSetupAlreadyCompleted;
use InvalidArgumentException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:administrator:create-initial-owner',
    description: '初期ownerを対話形式で作成します。',
)]
final class CreateInitialOwnerCommand extends Command
{
    public function __construct(
        private readonly CreateInitialOwner $createInitialOwner,
        private readonly BeginAdministratorTotpEnrollment $beginTotpEnrollment,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if (!$input->isInteractive()) {
            $io->error('資格情報をコマンド引数や標準入力へ露出しないため、この初期設定コマンドは対話モードでのみ実行できます。');

            return Command::INVALID;
        }

        $loginId = self::askString($io, 'ログインID');
        $displayName = self::askString($io, '表示名');
        $plainPassword = self::askHiddenString($io, 'パスワード');
        $passwordConfirmation = self::askHiddenString($io, 'パスワード（確認）');
        $totpSecret = '';
        $provisioningUri = '';
        $confirmationCode = '';

        try {
            if (!hash_equals($plainPassword, $passwordConfirmation)) {
                $io->error('入力したパスワードが一致しません。初期ownerは作成されていません。');

                return Command::INVALID;
            }

            try {
                $enrollment = $this->beginTotpEnrollment->begin($loginId);
            } catch (InvalidArgumentException $exception) {
                $io->error($exception->getMessage());

                return Command::INVALID;
            }

            $totpSecret = $enrollment->secret;
            $provisioningUri = $enrollment->provisioningUri;
            unset($enrollment);

            $io->section('TOTPの登録');
            $io->text('次の登録URIを認証アプリへ登録してください。このURIにはTOTP秘密値が含まれるため、安全に取り扱ってください。');
            $io->writeln($provisioningUri);
            $confirmationCode = self::askHiddenString($io, '認証アプリに表示された6桁コード');

            try {
                $result = $this->createInitialOwner->create(
                    $loginId,
                    $displayName,
                    $plainPassword,
                    $totpSecret,
                    $confirmationCode,
                );
            } catch (AdministratorPasswordRejected|InitialSetupAlreadyCompleted|InvalidArgumentException $exception) {
                $io->error($exception->getMessage());

                return Command::FAILURE;
            }

            if ($result === null) {
                $io->error('TOTPコードを確認できませんでした。初期ownerは作成されていません。');

                return Command::FAILURE;
            }

            $recoveryCodes = $result->consumeRecoveryCodes();
            try {
                $io->section('回復コード');
                $io->warning('回復コードはこの画面で一度だけ表示します。安全な場所へ保存してください。');
                $io->listing($recoveryCodes);
            } finally {
                self::eraseSecrets($recoveryCodes);
            }

            $io->success('初期ownerを作成しました。');

            return Command::SUCCESS;
        } finally {
            sodium_memzero($plainPassword);
            sodium_memzero($passwordConfirmation);
            sodium_memzero($totpSecret);
            sodium_memzero($provisioningUri);
            sodium_memzero($confirmationCode);
        }
    }

    private static function askString(SymfonyStyle $io, string $question): string
    {
        $answer = $io->ask($question);

        return is_string($answer) ? $answer : '';
    }

    private static function askHiddenString(SymfonyStyle $io, string $question): string
    {
        $answer = $io->askHidden($question);

        return is_string($answer) ? $answer : '';
    }

    /** @param list<string> $secrets */
    private static function eraseSecrets(array &$secrets): void
    {
        foreach ($secrets as &$secret) {
            sodium_memzero($secret);
        }

        unset($secret);
    }
}
