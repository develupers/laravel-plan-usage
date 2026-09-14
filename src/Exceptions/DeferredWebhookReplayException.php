<?php

declare(strict_types=1);

namespace Develupers\PlanUsage\Exceptions;

use RuntimeException;

/**
 * Thrown after a webhook delivery was processed but replaying one or more
 * deferred sibling deliveries failed. The triggering event stays processed
 * (its own work committed); failing the job makes the backlog visible and
 * lets a queue retry or a redelivery drain it again.
 */
class DeferredWebhookReplayException extends RuntimeException {}
