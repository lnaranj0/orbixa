<?php

namespace Modules\Portfolio\Contracts;

/** Minimal data other modules need about a visible project (no free text). */
final readonly class ProjectReference
{
    public function __construct(
        public string $id,
        public string $key,
        public string $status,
        public string $confidentiality,
    ) {}

    public function isArchived(): bool
    {
        return $this->status === 'archived';
    }
}
