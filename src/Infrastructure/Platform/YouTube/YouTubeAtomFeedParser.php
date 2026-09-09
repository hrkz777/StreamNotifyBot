<?php

declare(strict_types=1);

namespace App\Infrastructure\Platform\YouTube;

use InvalidArgumentException;
use XMLReader;

final class YouTubeAtomFeedParser
{
    private const ATOM_NAMESPACE = 'http://www.w3.org/2005/Atom';
    private const YOUTUBE_NAMESPACE = 'http://www.youtube.com/xml/schemas/2015';
    private const MAX_PAYLOAD_BYTES = 1048576;

    /** @return list<YouTubeAtomFeedEntry> */
    public function parse(string $payload): array
    {
        if ($payload === '' || strlen($payload) > self::MAX_PAYLOAD_BYTES) {
            throw new InvalidArgumentException('YouTube Atomフィード本文は1バイト以上1MiB以下で指定してください。');
        }

        $previousErrorHandling = libxml_use_internal_errors(true);
        libxml_clear_errors();

        try {
            $reader = $this->reader($payload);
            $feedDepth = null;
            $entries = [];

            while ($reader->read()) {
                if ($reader->nodeType === XMLReader::DOC_TYPE) {
                    throw new InvalidArgumentException('YouTube AtomフィードにDTDは使用できません。');
                }

                if ($reader->nodeType !== XMLReader::ELEMENT) {
                    continue;
                }

                if ($feedDepth === null) {
                    if ($reader->depth !== 0 || $reader->localName !== 'feed' || $reader->namespaceURI !== self::ATOM_NAMESPACE) {
                        throw new InvalidArgumentException('YouTube Atomフィードのルート要素が不正です。');
                    }

                    $feedDepth = $reader->depth;

                    continue;
                }

                if (
                    $reader->depth === $feedDepth + 1
                    && $reader->localName === 'entry'
                    && $reader->namespaceURI === self::ATOM_NAMESPACE
                ) {
                    $entries[] = $this->parseEntry($reader->readOuterXml());
                }
            }

            if ($feedDepth === null || libxml_get_errors() !== []) {
                throw new InvalidArgumentException('YouTube AtomフィードのXML形式が不正です。');
            }

            return $entries;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrorHandling);
        }
    }

    private function reader(string $xml): XMLReader
    {
        $reader = new XMLReader();
        if (!$reader->XML($xml, null, LIBXML_COMPACT | LIBXML_NONET)) {
            throw new InvalidArgumentException('YouTube AtomフィードのXML形式が不正です。');
        }

        $reader->setParserProperty(XMLReader::LOADDTD, false);
        $reader->setParserProperty(XMLReader::DEFAULTATTRS, false);
        $reader->setParserProperty(XMLReader::VALIDATE, false);
        $reader->setParserProperty(XMLReader::SUBST_ENTITIES, false);

        return $reader;
    }

    private function parseEntry(string $xml): YouTubeAtomFeedEntry
    {
        $reader = $this->reader($xml);
        $entryDepth = null;
        $videoId = null;
        $channelId = null;

        while ($reader->read()) {
            if ($reader->nodeType === XMLReader::DOC_TYPE) {
                throw new InvalidArgumentException('YouTube AtomフィードにDTDは使用できません。');
            }

            if ($reader->nodeType !== XMLReader::ELEMENT) {
                continue;
            }

            if ($entryDepth === null) {
                if ($reader->depth !== 0 || $reader->localName !== 'entry' || $reader->namespaceURI !== self::ATOM_NAMESPACE) {
                    throw new InvalidArgumentException('YouTube Atomフィードのエントリ形式が不正です。');
                }

                $entryDepth = $reader->depth;

                continue;
            }

            if ($reader->depth !== $entryDepth + 1 || $reader->namespaceURI !== self::YOUTUBE_NAMESPACE) {
                continue;
            }

            if ($reader->localName === 'videoId') {
                $videoId = $this->readIdentifier($reader, $videoId, '動画ID');
            }

            if ($reader->localName === 'channelId') {
                $channelId = $this->readIdentifier($reader, $channelId, 'チャンネルID');
            }
        }

        if (
            $entryDepth === null
            || libxml_get_errors() !== []
            || $videoId === null
            || $channelId === null
            || preg_match('/^[A-Za-z0-9_-]{11}$/D', $videoId) !== 1
            || preg_match('/^UC[A-Za-z0-9_-]{22}$/D', $channelId) !== 1
        ) {
            throw new InvalidArgumentException('YouTube Atomフィードの動画またはチャンネルIDが不正です。');
        }

        return new YouTubeAtomFeedEntry($videoId, $channelId);
    }

    private function readIdentifier(XMLReader $reader, ?string $currentValue, string $label): string
    {
        if ($currentValue !== null || $reader->isEmptyElement) {
            throw new InvalidArgumentException(sprintf('YouTube Atomフィードの%sが重複または空です。', $label));
        }

        $value = $reader->readString();
        if ($value === '') {
            throw new InvalidArgumentException(sprintf('YouTube Atomフィードの%sが空です。', $label));
        }

        return $value;
    }
}
