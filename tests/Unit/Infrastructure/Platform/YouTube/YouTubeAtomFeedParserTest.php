<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Platform\YouTube;

use App\Infrastructure\Platform\YouTube\YouTubeAtomFeedParser;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class YouTubeAtomFeedParserTest extends TestCase
{
    #[Test]
    public function itParsesYouTubeVideoAndChannelIdentifiers(): void
    {
        $entries = (new YouTubeAtomFeedParser())->parse(<<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <feed xmlns="http://www.w3.org/2005/Atom" xmlns:yt="http://www.youtube.com/xml/schemas/2015">
                <entry>
                    <yt:videoId>abcdefghijk</yt:videoId>
                    <yt:channelId>UCabcdefghijklmnopqrstuv</yt:channelId>
                </entry>
            </feed>
            XML);

        self::assertCount(1, $entries);
        self::assertSame('abcdefghijk', $entries[0]->videoId);
        self::assertSame('UCabcdefghijklmnopqrstuv', $entries[0]->channelId);
    }

    #[Test]
    public function itRejectsDoctypes(): void
    {
        $parser = new YouTubeAtomFeedParser();

        $this->expectException(InvalidArgumentException::class);
        $parser->parse(<<<'XML'
            <!DOCTYPE feed [<!ENTITY test "value">]>
            <feed xmlns="http://www.w3.org/2005/Atom" xmlns:yt="http://www.youtube.com/xml/schemas/2015">
                <entry><yt:videoId>&test;</yt:videoId></entry>
            </feed>
            XML);
    }

    #[Test]
    public function itRejectsAnIncompleteEntry(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new YouTubeAtomFeedParser())->parse(<<<'XML'
            <feed xmlns="http://www.w3.org/2005/Atom" xmlns:yt="http://www.youtube.com/xml/schemas/2015">
                <entry><yt:videoId>abcdefghijk</yt:videoId></entry>
            </feed>
            XML);
    }
}
