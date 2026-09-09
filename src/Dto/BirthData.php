<?php

declare(strict_types=1);

namespace Astroway\Dto;

/**
 * Birth-moment input shared across natal, transits, Human Design, Vedic.
 *
 * Required: $date (YYYY-MM-DD), $time (HH:MM:SS), $latitude and $longitude in
 * decimal degrees.
 *
 * The coordinates used to default to 0, which sent a real request for 0N 0E in
 * the Gulf of Guinea, and the server cannot tell that apart from a deliberate
 * one. api-calc stopped defaulting them in 2.141.0: every /reports/* path
 * answers 400 without them, and the JSON chart endpoints answer with a
 * Deprecation header until 2026-11-09 and a 400 after it. They keep their
 * position in the signature so no positional call changes meaning; omitting
 * them now throws instead of charting the Atlantic.
 *
 * $timezoneOffset still defaults to 0, meaning UTC, and is hours rather than
 * minutes: 5.5 for India, 5.75 for Nepal, -3.5 for Newfoundland.
 *
 * Convert to wire format via toArray() — service classes do this automatically
 * when you pass a DTO to a namespace method.
 */
final readonly class BirthData
{
    public function __construct(
        public string $date,
        public string $time,
        public float $timezoneOffset = 0,
        public ?float $latitude = null,
        public ?float $longitude = null,
        public string $houseSystem = 'P',
        public ?string $name = null,
        public ?string $city = null,
        public ?string $zodiacType = null,
        public ?float $ayanamsaId = null,
        public ?bool $cosmogram = null,
    ) {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new \InvalidArgumentException("BirthData: date must be YYYY-MM-DD, got '{$date}'");
        }
        if (!preg_match('/^\d{2}:\d{2}:\d{2}$/', $time)) {
            throw new \InvalidArgumentException("BirthData: time must be HH:MM:SS, got '{$time}'");
        }
        if ($latitude === null || $longitude === null) {
            throw new \InvalidArgumentException(
                'BirthData: latitude and longitude are required, in decimal degrees. '
                .'They used to default to 0, which is a real place in the Gulf of Guinea, '
                .'and the API stopped accepting the omission on reports in 2.141.0.'
            );
        }
        if ($latitude < -90 || $latitude > 90) {
            throw new \InvalidArgumentException("BirthData: latitude must be between -90 and 90, got {$latitude}");
        }
        if ($longitude < -180 || $longitude > 180) {
            throw new \InvalidArgumentException("BirthData: longitude must be between -180 and 180, got {$longitude}");
        }
        if ($timezoneOffset < -14 || $timezoneOffset > 14) {
            throw new \InvalidArgumentException(
                "BirthData: timezoneOffset is hours from UTC, between -14 and 14, got {$timezoneOffset}. "
                .'Minutes (330 for India) used to be accepted and answered with a chart for the wrong moment.'
            );
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
            'houseSystem' => $this->houseSystem,
        ];
        if ($this->name !== null) {
            $out['name'] = $this->name;
        }
        if ($this->city !== null) {
            $out['city'] = $this->city;
        }
        if ($this->zodiacType !== null) {
            $out['zodiacType'] = $this->zodiacType;
        }
        if ($this->ayanamsaId !== null) {
            $out['ayanamsaId'] = $this->ayanamsaId;
        }
        if ($this->cosmogram !== null) {
            $out['cosmogram'] = $this->cosmogram;
        }

        return $out;
    }
}
