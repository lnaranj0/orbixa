<?php

namespace Modules\GitHubIntegration\Application;

/**
 * Linking an installation failed. One generic message for every cause
 * (foreign or unknown installation, no user access, suspended, claimed by
 * another organization): nothing about other organizations is revealed (G-C).
 */
final class InstallationRejected extends \RuntimeException
{
    public const MESSAGE = 'The GitHub installation could not be linked.';

    public static function make(): self
    {
        return new self(self::MESSAGE);
    }
}
