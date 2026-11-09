<?php

declare(strict_types=1);

namespace TrilbyMedia\Cap\Storage;

/** Optional atomic read-and-delete for single-use verification tokens. */
interface AtomicTokenStorageInterface extends TokenStorageInterface
{
    public function consumeToken(string $key): ?int;
}
