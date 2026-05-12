<?php

declare(strict_types=1);

namespace Astroway\Helpers;

/**
 * Birth-moment helper. Wraps the (date, time, lat, lon, tz) tuple every
 * astrology call needs and serializes it to the wire shape used by all
 * AstroWay calc endpoints.
 *
 * Three factories:
 *   - fromCoordinates() — most common; pass lat/lon/tz directly
 *   - fromDateTimeImmutable() — when you already have a \DateTimeImmutable
 *   - parse() — accepts ISO-8601 string and lat/lon/tz
 *
 * `fromCity()` is intentionally not in beta.1 — it would call
 * `/v1/geo/search`, which is on the api-calc roadmap. Until then, do the
 * geocoding yourself and feed the coordinates here.
 */
final readonly class BirthDateTime
{
    public function __construct(
        public string $date,
        public string $time,
        public float $latitude,
        public float $longitude,
        public float $timezoneOffset,
        public ?string $name = null,
        public ?string $city = null,
    ) {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new \InvalidArgumentException("BirthDateTime: date must be YYYY-MM-DD, got '{$date}'");
        }
        if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $time)) {
            throw new \InvalidArgumentException("BirthDateTime: time must be HH:MM or HH:MM:SS, got '{$time}'");
        }
        if ($latitude < -90 || $latitude > 90) {
            throw new \InvalidArgumentException("BirthDateTime: latitude out of range [-90, 90]: {$latitude}");
        }
        if ($longitude < -180 || $longitude > 180) {
            throw new \InvalidArgumentException("BirthDateTime: longitude out of range [-180, 180]: {$longitude}");
        }
        if ($timezoneOffset < -14 || $timezoneOffset > 14) {
            throw new \InvalidArgumentException("BirthDateTime: timezoneOffset out of range [-14, 14]: {$timezoneOffset}");
        }
    }

    /**
     * Build from coordinates. Accepts both `HH:MM` and `HH:MM:SS` for $time
     * and normalises to `HH:MM:SS` for the wire format.
     */
    public static function fromCoordinates(
        string $date,
        string $time,
        float $latitude,
        float $longitude,
        float $timezoneOffset,
        ?string $name = null,
        ?string $city = null,
    ): self {
        return new self(
            date: $date,
            time: self::normaliseTime($time),
            latitude: $latitude,
            longitude: $longitude,
            timezoneOffset: $timezoneOffset,
            name: $name,
            city: $city,
        );
    }

    /**
     * Build from a `\DateTimeImmutable`. The instance's offset (in hours)
     * becomes `timezoneOffset`. Pass UTC + an explicit `$timezoneOffset` if
     * the original birth tz differs.
     */
    public static function fromDateTimeImmutable(
        \DateTimeImmutable $dt,
        float $latitude,
        float $longitude,
        ?float $timezoneOffset = null,
        ?string $name = null,
        ?string $city = null,
    ): self {
        $offset = $timezoneOffset ?? ($dt->getOffset() / 3600);

        return new self(
            date: $dt->format('Y-m-d'),
            time: $dt->format('H:i:s'),
            latitude: $latitude,
            longitude: $longitude,
            timezoneOffset: $offset,
            name: $name,
            city: $city,
        );
    }

    /**
     * Parse an ISO-8601 timestamp like `1990-07-14T14:30:00+03:00` and
     * combine with coordinates. If the ISO string carries no offset, you
     * must pass `$timezoneOffset` explicitly.
     */
    public static function parse(
        string $iso,
        float $latitude,
        float $longitude,
        ?float $timezoneOffset = null,
        ?string $name = null,
        ?string $city = null,
    ): self {
        try {
            $dt = new \DateTimeImmutable($iso);
        } catch (\Throwable $e) {
            throw new \InvalidArgumentException("BirthDateTime::parse: invalid ISO-8601 '{$iso}': {$e->getMessage()}");
        }
        $hasOffset = (bool) preg_match('/(Z|[+-]\d{2}:?\d{2})$/', $iso);
        if (!$hasOffset && $timezoneOffset === null) {
            throw new \InvalidArgumentException(
                "BirthDateTime::parse: '{$iso}' has no timezone offset; pass \$timezoneOffset explicitly",
            );
        }

        return self::fromDateTimeImmutable($dt, $latitude, $longitude, $timezoneOffset, $name, $city);
    }

    /**
     * Wire-format payload. Field names match what every calc endpoint expects.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = [
            'date' => $this->date,
            'time' => $this->time,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'timezoneOffset' => $this->timezoneOffset,
        ];
        if ($this->name !== null) {
            $out['name'] = $this->name;
        }
        if ($this->city !== null) {
            $out['city'] = $this->city;
        }

        return $out;
    }

    /**
     * Reconstruct a `\DateTimeImmutable` in the original timezone.
     */
    public function toDateTimeImmutable(): \DateTimeImmutable
    {
        $sign = $this->timezoneOffset >= 0 ? '+' : '-';
        $abs = abs($this->timezoneOffset);
        $hours = (int) floor($abs);
        $minutes = (int) round(($abs - $hours) * 60);
        $tz = sprintf('%s%02d:%02d', $sign, $hours, $minutes);

        return new \DateTimeImmutable("{$this->date}T{$this->time}{$tz}");
    }

    private static function normaliseTime(string $time): string
    {
        return strlen($time) === 5 ? $time.':00' : $time;
    }
}
