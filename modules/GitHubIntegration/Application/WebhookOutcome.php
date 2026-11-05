<?php

namespace Modules\GitHubIntegration\Application;

enum WebhookOutcome: string
{
    /** Stored in the organization's inbox for processing. */
    case Accepted = 'accepted';

    /** Valid but not for Orbixa: unlinked installation, unselected repository or unhandled event. Payload not stored. */
    case Ignored = 'ignored';

    /** Same delivery id, or same body under a new id: no effect. */
    case Duplicate = 'duplicate';
}
