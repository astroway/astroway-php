<?php

declare(strict_types=1);

namespace Astroway\Dto;

/**
 * Transits to a natal chart at a target moment.
 *
 * Birth fields are inlined rather than nested under `birth` to match the
 * on-the-wire shape for /transits (flat object, not nested).
 *
 * $targetDate defaults to "now" server-side when omitted — pass an explicit
 * YYYY-MM-DD (and optionally $targetTime) for a fixed date.
 */
final readonly class TransitsRequest
{
    public function __construct(
        public string $date,
        public string $time,
        public float $timezoneOffset = 0,
        public float $latitude = 0,
        public float $longitude = 0,
        public ?string $targetDate = null,
        public ?string $targetTime = null,
        public ?float $targetTimezoneOffset = null,
        public ?float $targetLatitude = null,
        public ?float $targetLongitude = null,
    ) {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new \InvalidArgumentException("TransitsRequest: date must be YYYY-MM-DD, got '{$date}'");
        }
        if (!preg_match('/^\d{2}:\d{2}:\d{2}$/', $time)) {
            throw new \InvalidArgumentException("TransitsRequest: time must be HH:MM:SS, got '{$time}'");
        }
        if ($targetDate !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $targetDate)) {
            throw new \InvalidArgumentException("TransitsRequest: targetDate must be YYYY-MM-DD, got '{$targetDate}'");
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
            'targetDate' => $this->targetDate,
            'targetTime' => $this->targetTime,
            'targetTimezoneOffset' => $this->targetTimezoneOffset,
            'targetLatitude' => $this->targetLatitude,
            'targetLongitude' => $this->targetLongitude,
        ] as $key => $value) {
            if ($value !== null) {
                $out[$key] = $value;
            }
        }

        return $out;
    }
}
