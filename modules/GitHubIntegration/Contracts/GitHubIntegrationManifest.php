<?php

namespace Modules\GitHubIntegration\Contracts;

use Modules\Platform\Contracts\Modules\ModuleManifest;

final class GitHubIntegrationManifest implements ModuleManifest
{
    public function module(): string
    {
        return 'GitHubIntegration';
    }

    public function ownedTables(): array
    {
        return [
            'integrations.github_installations',
            'integrations.github_repositories',
            'integrations.repository_project_links',
            'integrations.webhook_inbox',
            'integrations.webhook_deliveries',
        ];
    }
}
