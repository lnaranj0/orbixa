<?php

namespace Modules\GitHubIntegration\Application\Ports;

/** Identified by its numeric id; the name changes on rename or transfer (G-C). */
final readonly class GitHubRepository
{
    public function __construct(
        public int $id,
        public string $fullName,
        public bool $private,
    ) {}
}
