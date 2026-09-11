<?php

declare(strict_types=1);

namespace Astroway\Streaming;

/**
 * One normalised frame of a Server-Sent Events response.
 *
 * `type` is the discriminator, and matches the TypeScript and Python SDKs:
 * `text_delta` carries `text`, `done` ends the stream, `error` carries
 * `message` and an optional `code`, and `event` is anything this SDK does not
 * recognise, left intact in `event` and `data` so a server-side addition is
 * readable without an SDK release.
 */
final class StreamChunk
{
    /**
     * @param array<string, mixed>|string|null $data
     */
    public function __construct(
        public readonly string $type,
        public readonly string $text = '',
        public readonly string $event = 'message',
        public readonly array|string|null $data = null,
        public readonly string $rawData = '',
        public readonly ?string $message = null,
        public readonly ?string $code = null,
    ) {
    }
}
