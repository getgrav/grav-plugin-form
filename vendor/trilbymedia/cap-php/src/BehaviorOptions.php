<?php

declare(strict_types=1);

namespace TrilbyMedia\Cap;

/** Experimental behavior policy; enabling it also enables instrumentation. */
final class BehaviorOptions
{
    public function __construct(
        public readonly bool $block = false,
        public readonly bool $strict = false,
        public readonly bool $requireInteraction = true,
        public readonly float $threshold = 0.75,
        public readonly string|false $blockAgents = false,
    ) {
        if (!is_finite($threshold) || $threshold < 0.1 || $threshold > 1) {
            throw new \InvalidArgumentException('Behavior threshold must be between 0.1 and 1');
        }
        if (!in_array($blockAgents, [false, 'all', 'identified'], true)) {
            throw new \InvalidArgumentException('blockAgents must be false, all or identified');
        }
    }

    /** @internal */
    public function policy(): array
    {
        return ['block' => $this->block, 'strict' => $this->strict, 'requireInteraction' => $this->requireInteraction, 'threshold' => $this->threshold, 'blockAgents' => $this->blockAgents];
    }
}
