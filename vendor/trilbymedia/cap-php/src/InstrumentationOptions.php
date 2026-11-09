<?php

declare(strict_types=1);

namespace TrilbyMedia\Cap;

/** Opt-in browser instrumentation policy, captured when a challenge is issued. */
final class InstrumentationOptions
{
    public function __construct(
        public readonly bool $blockAutomatedBrowsers = false,
        public readonly bool $detectAutomatedInteractions = false,
        public readonly bool $blockAutomatedInteractions = false,
    ) {}
}
