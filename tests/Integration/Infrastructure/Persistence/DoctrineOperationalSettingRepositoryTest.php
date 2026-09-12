<?php

declare(strict_types=1);

namespace App\Tests\Integration\Infrastructure\Persistence;

use App\Domain\System\ConcurrentOperationalSettingUpdate;
use App\Domain\System\OperationalSetting;
use App\Infrastructure\Persistence\DoctrineOperationalSettingRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DoctrineOperationalSettingRepositoryTest extends KernelTestCase
{
    private Connection $connection;
    private DoctrineOperationalSettingRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->connection->beginTransaction();
        $this->repository = new DoctrineOperationalSettingRepository($this->connection);
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    #[Test]
    public function itLoadsAllOperationalSettings(): void
    {
        $settings = $this->repository->findAll();

        self::assertCount(28, $settings);
        self::assertSame('polling_ended_twitcasting', $settings[0]->key);
        $last = array_pop($settings);
        self::assertInstanceOf(OperationalSetting::class, $last);
        self::assertSame('retention_delivery_results', $last->key);
    }

    #[Test]
    public function itUpdatesAllSettingsAtomically(): void
    {
        $settings = $this->settingsByKey();
        $time = new DateTimeImmutable('2026-09-12 01:02:03.123456+00:00');
        $this->repository->saveAll([
            $this->replace($settings['polling_scheduled_youtube'], 901, $time),
            $this->replace($settings['retention_delivery_results'], 29, $time),
        ]);

        $stored = $this->settingsByKey();

        self::assertSame(901, $stored['polling_scheduled_youtube']->value);
        self::assertSame(29, $stored['retention_delivery_results']->value);
        self::assertSame(1, $stored['polling_scheduled_youtube']->lockVersion);
        self::assertSame(1, $stored['retention_delivery_results']->lockVersion);
    }

    #[Test]
    public function itRollsBackEverySettingWhenOneOptimisticLockIsStale(): void
    {
        $settings = $this->settingsByKey();
        $this->repository->save($this->replace($settings['retention_delivery_results'], 29));

        $this->expectException(ConcurrentOperationalSettingUpdate::class);

        try {
            $this->repository->saveAll([
                $this->replace($settings['polling_scheduled_youtube'], 901),
                $this->replace($settings['retention_delivery_results'], 28),
            ]);
        } finally {
            self::assertSame(900, $this->settingsByKey()['polling_scheduled_youtube']->value);
        }
    }

    /** @return array<string, OperationalSetting> */
    private function settingsByKey(): array
    {
        $settings = [];
        foreach ($this->repository->findAll() as $setting) {
            $settings[$setting->key] = $setting;
        }

        return $settings;
    }

    private function replace(OperationalSetting $setting, int $value, ?DateTimeImmutable $updatedAt = null): OperationalSetting
    {
        return new OperationalSetting($setting->key, $value, $updatedAt ?? new DateTimeImmutable('2026-09-12 01:02:03.123456+00:00'), $setting->lockVersion);
    }
}
