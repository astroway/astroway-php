<?php

declare(strict_types=1);

namespace Astroway\Dto;

/**
 * Typed result of `GET /natal-texts`. `texts` is keyed by the request key
 * (`sun.aries`, `moon.h4`, `sun_moon.trine`); a key with no text in `lang`
 * lands in `missing` instead, never filled from another language.
 */
final readonly class NatalTextsResult
{
    /**
     * @param array<string, NatalTextEntry> $texts
     * @param list<string>                  $missing
     */
    public function __construct(
        public string $lang,
        public array $texts,
        public array $missing,
    ) {
    }

    /**
     * @param array{lang: string, texts: array<string, array{title: string, body: string, kind: string}>, missing?: list<string>} $data
     */
    public static function fromArray(array $data): self
    {
        $texts = [];
        foreach ($data['texts'] as $key => $entry) {
            $texts[$key] = new NatalTextEntry(
                title: $entry['title'],
                body: $entry['body'],
                kind: $entry['kind'],
            );
        }

        return new self(
            lang: $data['lang'],
            texts: $texts,
            missing: $data['missing'] ?? [],
        );
    }
}
