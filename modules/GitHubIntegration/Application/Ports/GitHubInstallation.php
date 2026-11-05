<?php

namespace Modules\GitHubIntegration\Application\Ports;

final readonly class GitHubInstallation
{
    public function __construct(
        public int $id,
        public int $accountId,
        public string $accountLogin,
        public string $accountType,
        public bool $suspended,
    ) {}
}
