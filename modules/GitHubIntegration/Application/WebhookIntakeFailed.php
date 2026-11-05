<?php

namespace Modules\GitHubIntegration\Application;

/**
 * A database failure while taking a webhook. Carries the SQLSTATE only: the
 * framework's QueryException message embeds the bindings, i.e. the payload
 * of a possibly private repository, so it is never chained or reported.
 */
final class WebhookIntakeFailed extends \RuntimeException
{
    public static function sqlState(string $state): self
    {
        return new self("Webhook intake failed (SQLSTATE {$state}).");
    }
}
