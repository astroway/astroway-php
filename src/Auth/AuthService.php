<?php

declare(strict_types=1);

namespace Astroway\Auth;

use Astroway\Astroway;

/**
 * `$aw->auth()`: self-service calls about the calling key.
 *
 * Hand-written because `/auth/*` carries the `System` tag, which
 * `scripts/generate-namespaces.php` skips.
 */
final class AuthService
{
    private ?KeysService $keys = null;

    public function __construct(private readonly Astroway $client)
    {
    }

    public function keys(): KeysService
    {
        return $this->keys ??= new KeysService($this->client);
    }
}
