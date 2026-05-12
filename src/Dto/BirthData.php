<?php

declare(strict_types=1);

namespace Astroway\Dto;

/**
 * Birth-moment input shared across natal, transits, Human Design, Vedic.
 *
 * Required: $date (YYYY-MM-DD), $time (HH:MM:SS).
 * Latitude/longitude/timezone default to 0 — pass real values for accurate
 * house cusps and ascendant.
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
        public float $latitude = 0,
        public float $longitude = 0,
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
