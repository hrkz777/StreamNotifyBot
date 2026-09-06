<?php

declare(strict_types=1);

namespace App\Presentation\Console;

use App\Application\Administration\BeginAdministratorTotpEnrollment;
use App\Application\Administration\CreateInitialOwner;
use App\Domain\Administration\AdministratorAlreadyExists;
use App\Domain\Administration\AdministratorPasswordRejected;
use App\Domain\Administration\InitialSetupAlreadyCompleted;
use App\Domain\System\InteractiveTerminal;
use InvalidArgumentException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\MissingInputException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

#[AsCommand(
    name: 'app:administrator:create-initial-owner',
    description: '対話形式で初期ownerを作成します。',
)]
final class CreateInitialOwnerCommand extends Command
{
    public function __construct(
        private readonly BeginAdministratorTotpEnrollment $beginTotpEnrollment,
        private readonly CreateInitialOwner $createInitialOwner,
        private readonly InteractiveTerminal $interactiveTerminal,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if (
            !$input->isInteractive()
            || !$this->interactiveTerminal->hasInteractiveInput()
            || !$this->interactiveTerminal->hasInteractiveOutput()
        ) {
            $io->error('初期ownerの作成は入出力が接続された対話端末でのみ実行できます。');

            return Command::FAILURE;
        }

        $password = '';
        $passwordConfirmation = '';
        $totpSecret = '';
        $provisioningUri = '';
        $confirmationCode = '';
        $recoveryCodes = [];
        $totpWasDisplayed = false;
        $ownerWasCreated = false;

        try {
            $loginId = self::askRequired($io, 'ログインID');
            $displayName = self::askRequired($io, '表示名');
            $enrollment = $this->beginTotpEnrollment->begin($loginId);
            $totpSecret = $enrollment->secret;
            $provisioningUri = $enrollment->provisioningUri;
            unset($enrollment);

            $io->warning('次のURIにはTOTP秘密値が含まれます。第三者へ共有せず、認証アプリへ一度だけ登録してください。');
            $io->writeln($provisioningUri);
            $totpWasDisplayed = true;
            self::erase($provisioningUri);
            $confirmationCode = self::askHiddenRequired($io, '認証アプリの6桁コード', true);
            $password = self::askHiddenRequired($io, 'パスワード', false);
            $passwordConfirmation = self::askHiddenRequired($io, 'パスワード（確認）', false);
            if (!hash_equals($password, $passwordConfirmation)) {
                $io->error('パスワードが一致しません。');
                self::writeUnusedTotpNotice($io);

                return Command::FAILURE;
            }

            self::erase($passwordConfirmation);

            $result = $this->createInitialOwner->create(
                $loginId,
                $displayName,
                $password,
                $totpSecret,
                $confirmationCode,
            );
            self::erase($password);
            self::erase($totpSecret);
            self::erase($confirmationCode);
            if ($result === null) {
                $io->error('確認コードを検証できませんでした。初期ownerは作成されていません。');
                self::writeUnusedTotpNotice($io);

                return Command::FAILURE;
            }

            $ownerWasCreated = true;
            $administratorId = $result->administratorId;
            $recoveryCodes = $result->consumeRecoveryCodes();
            unset($result);

            $io->warning('回復コードは再表示できません。第三者に共有せず、安全な場所へ保管してください。');
            $io->listing($recoveryCodes);
            $io->success(sprintf('初期ownerを作成しました。管理者ID: %s', $administratorId));

            return Command::SUCCESS;
        } catch (AdministratorPasswordRejected|InitialSetupAlreadyCompleted|AdministratorAlreadyExists $exception) {
            $io->error($exception->getMessage());
            if ($totpWasDisplayed) {
                self::writeUnusedTotpNotice($io);
            }

            return Command::FAILURE;
        } catch (InvalidArgumentException) {
            $io->error('入力内容が不正です。ログインID、表示名、パスワード、確認コードを見直してください。');
            if ($totpWasDisplayed) {
                self::writeUnusedTotpNotice($io);
            }

            return Command::FAILURE;
        } catch (MissingInputException) {
            $io->error('入力が中断されました。初期ownerは作成されていません。');
            if ($totpWasDisplayed) {
                self::writeUnusedTotpNotice($io);
            }

            return Command::FAILURE;
        } catch (Throwable) {
            $io->error($ownerWasCreated
                ? '初期ownerは作成済みですが、回復コードの表示を完了できませんでした。再実行せず、管理者IDの状態を確認してください。'
                : '初期ownerの作成結果を確認できませんでした。再実行せず、暗号鍵設定とMariaDBの状態を確認してください。');

            return Command::FAILURE;
        } finally {
            self::erase($password);
            self::erase($passwordConfirmation);
            self::erase($totpSecret);
            self::erase($provisioningUri);
            self::erase($confirmationCode);
            self::eraseAll($recoveryCodes);
        }
    }

    private static function askRequired(SymfonyStyle $io, string $label): string
    {
        $answer = $io->ask($label);
        if (!is_string($answer) || trim($answer) === '') {
            throw new InvalidArgumentException(sprintf('%sを入力してください。', $label));
        }

        return $answer;
    }

    private static function askHiddenRequired(SymfonyStyle $io, string $label, bool $trimmable): string
    {
        $question = new Question($label);
        $question->setHidden(true);
        $question->setHiddenFallback(false);
        $question->setTrimmable($trimmable);
        $answer = $io->askQuestion($question);
        if (!is_string($answer) || $answer === '') {
            throw new InvalidArgumentException(sprintf('%sを入力してください。', $label));
        }

        return $answer;
    }

    private static function erase(string &$value): void
    {
        $secretValue = $value;
        $value = '';

        if ($secretValue !== '') {
            sodium_memzero($secretValue);
        }
    }

    private static function writeUnusedTotpNotice(SymfonyStyle $io): void
    {
        $io->note('表示済みのTOTP登録は使用できません。認証アプリから削除して、状態確認後に再実行してください。');
    }

    /** @param list<string> $values */
    private static function eraseAll(array &$values): void
    {
        foreach ($values as &$value) {
            self::erase($value);
        }

        unset($value);
        $values = [];
    }
}
