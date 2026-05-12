<?php

declare(strict_types=1);

namespace Astroway\Dto;

/** Two-chart relationship analysis. Both charts use BirthData. */
final readonly class SynastryRequest
{
    public function __construct(
        public BirthData $chart1,
        public BirthData $chart2,
        public ?float $orbFactor = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $out = [
            'chart1' => $this->chart1->toArray(),
            'chart2' => $this->chart2->toArray(),
        ];
        if ($this->orbFactor !== null) {
            $out['orbFactor'] = $this->orbFactor;
        }

        return $out;
    }
}
