<?php

declare(strict_types=1);

namespace Develupers\PlanUsage\Exceptions;

use Exception;

class QuotaExceededException extends Exception
{
    protected string $featureSlug;

    protected ?float $limit;

    protected float $used;

    public function __construct(
        string $message = '',
        string $featureSlug = '',
        ?float $limit = null,
        float $used = 0,
        int $code = 0,
        ?Exception $previous = null
    ) {
        parent::__construct($message, $code, $previous);

        $this->featureSlug = $featureSlug;
        $this->limit = $limit;
        $this->used = $used;
    }

    public function getFeatureSlug(): string
    {
        return $this->featureSlug;
    }

    public function getLimit(): ?float
    {
        return $this->limit;
    }

    public function getUsed(): float
    {
        return $this->used;
    }

    public function getRemaining(): ?float
    {
        if ($this->limit === null) {
            return null;
        }

        return max(0, $this->limit - $this->used);
    }
}
