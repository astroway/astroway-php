<?php

declare(strict_types=1);

namespace Astroway\Auth;

use Astroway\Astroway;

/** `$aw->auth()->keys()`. */
final class KeysService
{
    public function __construct(private readonly Astroway $client)
    {
    }

    /**
     * Plan, balance and status of the calling key, `GET /v1/auth/keys/me`.
     *
     * Returns `key_prefix`, `plan`, `credits_remaining`,
     * `credits_total_this_period`, `period_end`, `status`, `domain`,
     * `domain_bound_at`, `created_at`, `referrer_source`. An unmetered key has
     * null credits and `unlimited: true`.
     *
     * @return array<string, mixed>
     */
    public function me(): array
    {
        /** @var array<string, mixed> $data */
        $data = $this->client->get('/auth/keys/me');

        return $data;
    }
}
