<?php

declare(strict_types=1);

namespace Astroway\Dto;

/**
 * One entry from `GET /natal-texts`. `kind` is 'planet_in_sign',
 * 'planet_in_house' or 'aspect'. `body` is plain text, paragraphs separated
 * by a blank line.
 */
final readonly class NatalTextEntry
{
    public function __construct(
        public string $title,
        public string $body,
        public string $kind,
    ) {
    }
}
