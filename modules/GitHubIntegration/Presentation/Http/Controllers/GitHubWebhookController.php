<?php

namespace Modules\GitHubIntegration\Presentation\Http\Controllers;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\RateLimiter;
use Modules\GitHubIntegration\Application\Ports\GitHubApiFailure;
use Modules\GitHubIntegration\Application\ReceiveWebhook;
use Modules\GitHubIntegration\Application\WebhookIntakeFailed;
use Modules\GitHubIntegration\Application\WebhookOutcome;
use Modules\GitHubIntegration\Infrastructure\Webhooks\WebhookSignature;

/**
 * POST /webhooks/github (G3): no session, CSRF or user. In order: size
 * (413, body unread past the limit), signature over the raw bytes (401,
 * before any parsing), headers and JSON (400), then intake. Error responses
 * carry no detail.
 */
final class GitHubWebhookController
{
    public function __construct(
        private readonly Config $config,
        private readonly WebhookSignature $signature,
        private readonly ReceiveWebhook $receive,
    ) {}

    public function __invoke(Request $request): Response|JsonResponse
    {
        if (! $this->config->get('github.webhooks_enabled')) {
            return response()->noContent(503);
        }
        $failures = 'github-webhook-signature:'.$request->ip();
        if (RateLimiter::tooManyAttempts($failures, (int) $this->config->get('github.webhook_failures_per_minute', 60))) {
            return response()->noContent(429);
        }
        $max = (int) $this->config->get('github.webhook_max_bytes');
        $declared = $request->headers->get('Content-Length');
        if ($declared !== null && (! ctype_digit($declared) || (int) $declared > $max)) {
            return response()->noContent(413);
        }
        $stream = $request->getContent(true);
        $body = stream_get_contents($stream, $max + 1);
        if ($body === false || strlen($body) > $max) {
            return response()->noContent(413);
        }

        try {
            $signed = $this->signature->matches($body, $request->headers->get('X-Hub-Signature-256'));
        } catch (GitHubApiFailure $failure) {
            // Reported at most every five minutes: unauthenticated callers must not flood the logs.
            RateLimiter::attempt('github-webhook-misconfigured', 1, fn () => report($failure), 300);

            return response()->noContent(503);
        }
        if (! $signed) {
            RateLimiter::hit($failures, 60);

            return response()->noContent(401);
        }

        $deliveryId = (string) $request->headers->get('X-GitHub-Delivery');
        $event = (string) $request->headers->get('X-GitHub-Event');
        $payload = json_decode($body, true, 128);
        if (preg_match('/^[A-Za-z0-9-]{1,64}$/', $deliveryId) !== 1 || preg_match('/^[a-z_]{1,64}$/', $event) !== 1
            || ! str_starts_with(ltrim($body), '{') || ! is_array($payload)) {
            return response()->noContent(400);
        }

        try {
            $outcome = $this->receive->handle($deliveryId, $event, $body, $payload);
        } catch (WebhookIntakeFailed $failure) {
            report($failure);

            return response()->noContent(500);
        }

        return response()->json(['status' => $outcome->value], $outcome === WebhookOutcome::Duplicate ? 200 : 202);
    }
}
