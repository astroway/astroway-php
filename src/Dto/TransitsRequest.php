<?php

declare(strict_types=1);

namespace Astroway\Dto;

/**
 * Transits to a natal chart at a chosen moment.
 *
 * Birth fields are inlined rather than nested under `birth` to match the
 * on-the-wire shape for /transits (flat object, not nested).
 *
 * $transitDate is required and names the moment to transit to; pass
 * $transitTime and $transitTzOffset when the hour matters. Earlier releases
 * declared $targetDate and four other names the API has never had, so every
 * call built from this DTO answered 400.
 *
 * $timezone takes an IANA zone name or 'auto' and wins over $timezoneOffset.
 */
final readonly class TransitsRequest
{
    public function __construct(
        public string $date,
        public string $time,
        public string $transitDate,
        public float $timezoneOffset = 0,
        public float $latitude = 0,
        public float $longitude = 0,
        public ?string $transitTime = null,
        public ?float $transitTzOffset = null,
        public ?string $timezone = null,
    ) {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new \InvalidArgumentException("TransitsRequest: date must be YYYY-MM-DD, got '{$date}'");
        }
        if (!preg_match('/^\d{2}:\d{2}:\d{2}$/', $time)) {
            throw new \InvalidArgumentException("TransitsRequest: time must be HH:MM:SS, got '{$time}'");
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $transitDate)) {
            throw new \InvalidArgumentException("TransitsRequest: transitDate must be YYYY-MM-DD, got '{$transitDate}'");
        }
        if ($transitTime !== null && !preg_match('/^\d{2}:\d{2}:\d{2}$/', $transitTime)) {
            throw new \InvalidArgumentException("TransitsRequest: transitTime must be HH:MM:SS, got '{$transitTime}'");
        }
        Timezone::assertValid($timezone);
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
            'transitDate' => $this->transitDate,
        ];
        foreach ([
            'transitTime' => $this->transitTime,
            'transitTzOffset' => $this->transitTzOffset,
            'timezone' => $this->timezone,
        ] as $key => $value) {
            if ($value !== null) {
                $out[$key] = $value;
            }
        }

        return $out;
    }
}
