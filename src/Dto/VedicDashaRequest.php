<?php

declare(strict_types=1);

namespace Astroway\Dto;

/**
 * Birth-moment input for Vedic dasha endpoints (vimshottari/yogini/ashtottari/...).
 *
 * Same shape as BirthData; declared separately for clarity at the call site.
 * Sidereal calculations default to Lahiri ayanamsa server-side.
 */
final readonly class VedicDashaRequest
{
    public function __construct(
        public string $date,
        public string $time,
        public float $timezoneOffset = 0,
        public float $latitude = 0,
        public float $longitude = 0,
        public ?float $ayanamsaId = null,
        public ?string $startDate = null,
        public ?string $endDate = null,
    ) {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new \InvalidArgumentException("VedicDashaRequest: date must be YYYY-MM-DD, got '{$date}'");
        }
        if (!preg_match('/^\d{2}:\d{2}:\d{2}$/', $time)) {
            throw new \InvalidArgumentException("VedicDashaRequest: time must be HH:MM:SS, got '{$time}'");
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $out = [
            'date' => $this->date,
            'time' => $this->time,
            'timezoneOffset' => $this->timezoneOffset,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
        ];
        foreach ([
            'ayanamsaId' => $this->ayanamsaId,
            'startDate' => $this->startDate,
            'endDate' => $this->endDate,
        ] as $key => $value) {
            if ($value !== null) {
                $out[$key] = $value;
            }
        }

        return $out;
    }
}
