<?php

declare(strict_types=1);

namespace App\Presentation\Console;

use App\Application\Administration\BeginAdministratorTotpEnrollment;
use App\Application\Administration\RecoverSoleOwnerCredentials;
use App\Domain\Administration\AdministratorPasswordRejected;
use App\Domain\Administration\ConcurrentSoleOwnerRecovery;
use App\Domain\Administration\SoleOwnerRecoveryUnavailable;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:administrator:recover-sole-owner',
    description: '単独の有効なownerのパスワード、TOTP、回復コードを安全に再設定します。',
)]
final class RecoverSoleOwnerCredentialsCommand extends Command
{
    public function __construct(
        private readonly RecoverSoleOwnerCredentials $recoverSoleOwnerCredentials,
        private readonly BeginAdministratorTotpEnrollment $beginTotpEnrollment,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if (!$input->isInteractive()) {
            $io->error('資格情報をコマンド引数や標準入力へ露出しないため、この回復コマンドは対話モードでのみ実行できます。');

            return Command::INVALID;
        }

        try {
            $target = $this->recoverSoleOwnerCredentials->findTarget();
        } catch (SoleOwnerRecoveryUnavailable $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $io->warning([
            'この操作は単独ownerのパスワード、TOTP、回復コードをすべて置き換えます。',
            '既存の管理者セッションと未使用トークンは失効します。',
        ]);
        $io->definitionList(
            ['ログインID' => OutputFormatter::escape($target->loginId)],
            ['表示名' => OutputFormatter::escape($target->displayName)],
        );

        if (!$io->confirm('このownerの資格情報を回復しますか？', false)) {
            $io->note('資格情報回復をキャンセルしました。');

            return Command::SUCCESS;
        }

        $plainPassword = self::askHiddenString($io, '新しいパスワード');
        $passwordConfirmation = self::askHiddenString($io, '新しいパスワード（確認）');
        $totpSecret = '';
        $provisioningUri = '';
        $confirmationCode = '';

        try {
            if (!hash_equals($plainPassword, $passwordConfirmation)) {
                $io->error('入力したパスワードが一致しません。資格情報は変更されていません。');

                return Command::INVALID;
            }

            $enrollment = $this->beginTotpEnrollment->begin($target->loginId);
            $totpSecret = $enrollment->secret;
            $provisioningUri = $enrollment->provisioningUri;
            unset($enrollment);

            $io->section('新しいTOTPの登録');
            $io->text('次の登録URIを認証アプリへ登録してください。このURIにはTOTP秘密値が含まれるため、安全に取り扱ってください。');
            $io->writeln($provisioningUri);
            $confirmationCode = self::askHiddenString($io, '認証アプリに表示された6桁コード');

            try {
                $result = $this->recoverSoleOwnerCredentials->recover(
                    $target,
                    $plainPassword,
                    $totpSecret,
                    $confirmationCode,
                );
            } catch (AdministratorPasswordRejected|ConcurrentSoleOwnerRecovery|SoleOwnerRecoveryUnavailable $exception) {
                $io->error($exception->getMessage());

                return Command::FAILURE;
            }

            if ($result === null) {
                $io->error('TOTPコードを確認できませんでした。資格情報は変更されていません。');

                return Command::FAILURE;
            }

            $recoveryCodes = $result->consumeRecoveryCodes();
            try {
                $io->section('新しい回復コード');
                $io->warning('回復コードはこの画面で一度だけ表示します。安全な場所へ保存してください。');
                $io->listing($recoveryCodes);
            } finally {
                self::eraseSecrets($recoveryCodes);
            }

            $io->success('単独ownerの資格情報を回復しました。');

            return Command::SUCCESS;
        } finally {
            sodium_memzero($plainPassword);
            sodium_memzero($passwordConfirmation);
            sodium_memzero($totpSecret);
            sodium_memzero($provisioningUri);
            sodium_memzero($confirmationCode);
        }
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
