<?php

declare(strict_types=1);

namespace Astroway\Tests;

use Astroway\Astroway;
use Astroway\Streaming\SseParser;
use Astroway\Streaming\StreamChunk;
use Astroway\Tests\Support\MockHttpClient;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;

/**
 * The two `/mcp/*` endpoints that answer `text/event-stream`.
 *
 * They were generated as ordinary JSON methods, and `request()` hands back an
 * unparsed string when the body is not JSON, so a caller got the raw frames to
 * split by hand. When api-calc corrected the declared media type, the JSON
 * filter in the generator would have dropped them from the surface instead.
 */
final class StreamingTest extends TestCase
{
    private const SSE = "data: {\"type\":\"token\",\"text\":\"A stellium \"}\n\n"
        ."data: {\"type\":\"token\",\"text\":\"is a cluster.\"}\n\n"
        ."data: {\"type\":\"done\",\"model\":\"gemini-2.5-flash\"}\n\n";

    public function testStreamingServiceMethodYieldsFrames(): void
    {
        $http = new MockHttpClient([
            new Response(200, ['content-type' => 'text/event-stream'], self::SSE),
        ]);
        $aw = new Astroway(['apiKey' => 'aw_test_x', 'httpClient' => $http]);

        $text = '';
        $types = [];
        foreach ($aw->mcp()->streaming(['message' => 'What is a stellium?']) as $chunk) {
            $types[] = $chunk->type;
            if ($chunk->type === 'text_delta') {
                $text .= $chunk->text;
            }
        }

        self::assertSame('A stellium is a cluster.', $text);
        self::assertSame(['text_delta', 'text_delta', 'done'], $types);
        self::assertSame('POST', $http->requests()[0]->getMethod());
        self::assertStringEndsWith('/mcp/streaming', (string) $http->requests()[0]->getUri());
        self::assertSame('text/event-stream', $http->requests()[0]->getHeaderLine('Accept'));
    }

    /** The shape `/v1/dev-assistant/stream` sends: an event name and a `delta`. */
    public function testParserUnderstandsTheEventNamedShape(): void
    {
        $chunks = iterator_to_array(SseParser::parse("event: token\ndata: {\"delta\":\"Hi\"}\n\n"));
        self::assertCount(1, $chunks);
        self::assertInstanceOf(StreamChunk::class, $chunks[0]);
        self::assertSame('text_delta', $chunks[0]->type);
        self::assertSame('Hi', $chunks[0]->text);
    }

    public function testAKnownEventNameWinsOverThePayload(): void
    {
        $chunks = iterator_to_array(SseParser::parse("event: done\ndata: {\"type\":\"token\",\"text\":\"ignored\"}\n\n"));
        self::assertSame('done', $chunks[0]->type);
    }

    public function testAnErrorFrameCarriesItsMessage(): void
    {
        $chunks = iterator_to_array(SseParser::parse("data: {\"type\":\"error\",\"message\":\"provider down\",\"code\":\"LLM_UNAVAILABLE\"}\n\n"));
        self::assertSame('error', $chunks[0]->type);
        self::assertSame('provider down', $chunks[0]->message);
        self::assertSame('LLM_UNAVAILABLE', $chunks[0]->code);
    }

    public function testAnUnknownFrameIsLeftIntact(): void
    {
        $chunks = iterator_to_array(SseParser::parse("event: ping\ndata: {\"type\":\"heartbeat\"}\n\n"));
        self::assertSame('event', $chunks[0]->type);
        self::assertSame('ping', $chunks[0]->event);
    }

    public function testACommentLineIsSkipped(): void
    {
        $chunks = iterator_to_array(SseParser::parse(": keep-alive\n\ndata: {\"type\":\"done\"}\n\n"));
        self::assertCount(1, $chunks);
        self::assertSame('done', $chunks[0]->type);
    }
}
