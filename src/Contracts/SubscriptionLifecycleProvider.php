<?php

declare(strict_types=1);

namespace Develupers\PlanUsage\Contracts;

/**
 * Umbrella contract for providers that manage the full subscription
 * lifecycle. Split into capabilities so actions depend only on what they
 * use: plan-change actions require SubscriptionPlanChangeProvider,
 * cancellation actions require SubscriptionCancellationProvider. Stripe,
 * Paddle, and Polar implement both.
 */
interface SubscriptionLifecycleProvider extends SubscriptionCancellationProvider, SubscriptionPlanChangeProvider {}
