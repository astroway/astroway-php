<?php

declare(strict_types=1);

namespace Astroway\Streaming;

/**
 * Parse a Server-Sent Events body into normalised chunks.
 *
 * The wire format is the SSE spec: lines, blocks separated by a blank line,
 * `event:` naming the frame and `data:` lines concatenated.
 *
 * Normalisation matches the other two SDKs, and covers the two shapes our own
 * API emits, neither of which any SDK recognised before 2026-09-02:
 * `/v1/mcp/streaming` sends no `event:` line and puts the kind in the payload,
 * `{"type":"token","text":"..."}`; `/v1/dev-assistant/stream` sends
 * `event: token` with `{"delta":"..."}`. An event name this parser knows wins
 * over a `type` inside the payload.
 */
final class SseParser
{
    private const KNOWN_EVENTS = ['text_delta', 'done', 'end', 'message_stop', 'error'];

    /**
     * @return \Generator<int, StreamChunk>
     */
    public static function parse(string $body): \Generator
    {
        $eventName = '';
        $dataLines = [];

        foreach (preg_split('/\r\n|\r|\n/', $body) ?: [] as $line) {
            if ($line === '') {
                if ($eventName !== '' || $dataLines !== []) {
                    yield self::normalise($eventName, implode("\n", $dataLines));
                }
                $eventName = '';
                $dataLines = [];
                continue;
            }
            if (str_starts_with($line, ':')) {
                continue; // comment / keep-alive
            }
            $colon = strpos($line, ':');
            if ($colon === false) {
                $field = $line;
                $value = '';
            } else {
                $field = substr($line, 0, $colon);
                $value = ltrim(substr($line, $colon + 1), ' ');
            }
            if ($field === 'event') {
                $eventName = $value;
            } elseif ($field === 'data') {
                $dataLines[] = $value;
            }
        }

        if ($eventName !== '' || $dataLines !== []) {
            yield self::normalise($eventName, implode("\n", $dataLines));
        }
    }

    private static function normalise(string $eventName, string $rawData): StreamChunk
    {
        $decoded = json_decode($rawData, true);
        /** @var array<string, mixed> $payload */
        $payload = is_array($decoded) ? $decoded : [];
        $data = is_array($decoded) ? $decoded : $rawData;
        $name = $eventName !== '' ? $eventName : 'message';

        $kind = in_array($name, self::KNOWN_EVENTS, true)
            ? $name
            : (is_string($payload['type'] ?? null) ? $payload['type'] : $name);

        if ($kind === 'token' || $kind === 'delta' || $kind === 'text_delta') {
            $text = $payload['text'] ?? $payload['delta'] ?? $payload['content'] ?? null;
            if (!is_string($text)) {
                $text = is_string($decoded) ? $decoded : $rawData;
            }

            return new StreamChunk('text_delta', text: $text, event: $name, data: $data, rawData: $rawData);
        }

        if (in_array($kind, ['done', 'end', 'message_stop'], true)) {
            return new StreamChunk('done', event: $name, data: $data, rawData: $rawData);
        }

        if ($kind === 'error') {
            $message = is_string($payload['message'] ?? null) ? $payload['message'] : 'stream emitted error event';
            $code = is_string($payload['code'] ?? null) ? $payload['code'] : null;

            return new StreamChunk('error', event: $name, data: $data, rawData: $rawData, message: $message, code: $code);
        }

        return new StreamChunk('event', event: $name, data: $data, rawData: $rawData);
    }
}
