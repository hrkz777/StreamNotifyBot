<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

use App\Domain\Security\EncryptionKey;
use App\Domain\Security\EncryptionKeyRing;
use App\Domain\Security\SecretConfigurationInvalid;
use JsonException;

final class JsonFileEncryptionKeyRing implements EncryptionKeyRing
{
    /** @var array<string, EncryptionKey>|null */
    private ?array $keys = null;
    private ?string $currentKeyId = null;

    public function __construct(private readonly string $keyRingFilePath)
    {
    }

    public function current(): EncryptionKey
    {
        $this->load();
        $currentKeyId = $this->currentKeyId;
        if ($currentKeyId === null || !isset($this->keys[$currentKeyId])) {
            throw new SecretConfigurationInvalid();
        }

        return $this->keys[$currentKeyId];
    }

    public function find(string $keyId): ?EncryptionKey
    {
        $this->load();

        return $this->keys[$keyId] ?? null;
    }

    private function load(): void
    {
        if ($this->keys !== null) {
            return;
        }

        if (!is_readable($this->keyRingFilePath)) {
            throw new SecretConfigurationInvalid();
        }

        $contents = @file_get_contents($this->keyRingFilePath);
        if (!is_string($contents) || $contents === '') {
            throw new SecretConfigurationInvalid();
        }

        try {
            $document = json_decode($contents, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new SecretConfigurationInvalid();
        }

        if (!is_array($document) || array_is_list($document)) {
            throw new SecretConfigurationInvalid();
        }

        $documentKeys = array_keys($document);
        sort($documentKeys);
        if ($documentKeys !== ['current_key_id', 'keys']) {
            throw new SecretConfigurationInvalid();
        }

        $currentKeyId = $document['current_key_id'];
        $keyEntries = $document['keys'];
        if (!is_string($currentKeyId) || !is_array($keyEntries) || !array_is_list($keyEntries) || $keyEntries === []) {
            throw new SecretConfigurationInvalid();
        }

        $keys = [];
        foreach ($keyEntries as $keyEntry) {
            $key = $this->parseKey($keyEntry);
            if (isset($keys[$key->id])) {
                throw new SecretConfigurationInvalid();
            }

            $keys[$key->id] = $key;
        }

        if (!isset($keys[$currentKeyId])) {
            throw new SecretConfigurationInvalid();
        }

        $this->keys = $keys;
        $this->currentKeyId = $currentKeyId;
    }

    private function parseKey(#[\SensitiveParameter] mixed $keyEntry): EncryptionKey
    {
        if (!is_array($keyEntry) || array_is_list($keyEntry)) {
            throw new SecretConfigurationInvalid();
        }

        $entryKeys = array_keys($keyEntry);
        sort($entryKeys);
        if ($entryKeys !== ['id', 'value']) {
            throw new SecretConfigurationInvalid();
        }

        $keyId = $keyEntry['id'];
        $encodedValue = $keyEntry['value'];
        if (!is_string($keyId) || !is_string($encodedValue)) {
            throw new SecretConfigurationInvalid();
        }

        $value = base64_decode($encodedValue, true);
        if (!is_string($value) || base64_encode($value) !== $encodedValue) {
            throw new SecretConfigurationInvalid();
        }

        try {
            return new EncryptionKey($keyId, $value);
        } catch (\InvalidArgumentException) {
            throw new SecretConfigurationInvalid();
        }
    }
}
