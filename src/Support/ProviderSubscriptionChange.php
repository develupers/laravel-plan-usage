<?php

declare(strict_types=1);

namespace Develupers\PlanUsage\Support;

use Carbon\CarbonImmutable;

final readonly class ProviderSubscriptionChange
{
    public function __construct(
        public string $providerSubscriptionId,
        public string $currentProductId,
        public ?string $pendingProductId,
        public CarbonImmutable $periodStart,
        public CarbonImmutable $periodEnd,
        public ?CarbonImmutable $effectiveAt = null,
        public ?string $providerChangeId = null,
    ) {}
}
