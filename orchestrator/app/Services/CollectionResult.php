<?php

namespace App\Services;

/**
 * What a single source yielded for a target: the profile fields it filled, a
 * status, and free-form meta (resolved domain, match verdict, detail, ...).
 */
final class CollectionResult
{
    /**
     * @param array<string, mixed> $fields profile fields this source produced
     * @param array<string, mixed> $meta   source-specific extras
     */
    public function __construct(
        public readonly string $source,
        public readonly string $status,   // ok | empty | skipped | error
        public readonly array $fields = [],
        public readonly array $meta = [],
    ) {
    }

    /**
     * @param array<string, mixed> $meta
     */
    public static function none(string $source, string $status = 'empty', array $meta = []): self
    {
        return new self($source, $status, [], $meta);
    }
}
