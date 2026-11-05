<?php

namespace Modules\GitHubIntegration\Application\Ports;

final readonly class RepositoryPage
{
    /** @param list<GitHubRepository> $repositories */
    public function __construct(
        public array $repositories,
        public ?int $nextPage,
    ) {}
}
