<?php

namespace App\Services\Loyverse;

/*
 * The request budget for the current window is spent — either our own guard
 * said no before the request left, or Loyverse itself answered 429. Callers
 * reschedule; they never spin in place against a shared account limit.
 */
class LoyverseBudgetExhausted extends LoyverseException
{
    public function __construct(
        public readonly int $retryAfterSeconds,
        string $message = 'The Loyverse request budget for this window is spent.',
    ) {
        parent::__construct($message);
    }
}
