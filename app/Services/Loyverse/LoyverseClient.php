<?php

namespace App\Services\Loyverse;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/*
 * The only door to Loyverse. Every request in the whole app goes through
 * `get()`, which is what makes the two account-wide facts enforceable in one
 * place: the token never appears anywhere else, and the 300-requests-per-300-
 * seconds budget is counted before a request leaves, not discovered as 429s.
 *
 * The guard is a fixed window in the cache (RateLimiter), sized by
 * config('loyverse.budget') — deliberately below the real limit, because the
 * budget belongs to the merchant account, not to this app. A 429 from
 * Loyverse anyway means someone else is spending it too; both cases surface
 * as LoyverseBudgetExhausted with a retry-after, and callers reschedule.
 */
class LoyverseClient
{
    private const BUDGET_KEY = 'loyverse:budget';

    private ?int $timeoutOverride = null;

    /** A per-call timeout, for callers on a tighter clock than the default */
    public function usingTimeout(int $seconds): static
    {
        $this->timeoutOverride = $seconds;

        return $this;
    }

    /** GET /merchant — the cheapest call that proves the token works */
    public function merchant(): array
    {
        return $this->get('/merchant');
    }

    /** GET /stores — Loyverse's store list, for linking branches */
    public function stores(): array
    {
        return $this->get('/stores', ['limit' => 250]);
    }

    /**
     * GET /receipts — one page, exactly as Loyverse answers it (the caller
     * owns cursor handling and the updated_at high-water mark).
     *
     * @param  array<string, mixed>  $query
     */
    public function receipts(array $query): array
    {
        return $this->get('/receipts', [...$query, 'limit' => $query['limit'] ?? 250]);
    }

    /**
     * GET /items — one page of the catalog, for the sales-filter search's
     * cached walk (the caller owns the cursor).
     *
     * @param  array<string, mixed>  $query
     */
    public function items(array $query): array
    {
        return $this->get('/items', [...$query, 'limit' => $query['limit'] ?? 250]);
    }

    /** Requests still available in the current window */
    public function remainingBudget(): int
    {
        return RateLimiter::remaining(self::BUDGET_KEY, config('loyverse.budget'));
    }

    /** @param array<string, mixed> $query */
    private function get(string $path, array $query = []): array
    {
        $token = (string) config('loyverse.token');
        if ($token === '') {
            throw new LoyverseNotConfigured('No Loyverse token is configured.');
        }

        $budget = (int) config('loyverse.budget');
        if (RateLimiter::tooManyAttempts(self::BUDGET_KEY, $budget)) {
            $retryAfter = RateLimiter::availableIn(self::BUDGET_KEY);
            Log::warning('Loyverse budget guard refused a request.', [
                'path' => $path, 'retry_after' => $retryAfter,
            ]);
            throw new LoyverseBudgetExhausted($retryAfter);
        }
        // Counted before it leaves — a failed request spent the budget too
        RateLimiter::hit(self::BUDGET_KEY, (int) config('loyverse.window'));

        try {
            $response = Http::withToken($token)
                ->acceptJson()
                ->baseUrl((string) config('loyverse.base_url'))
                ->timeout($this->timeoutOverride ?? (int) config('loyverse.timeout'))
                ->get($path, $query);
        } catch (ConnectionException $e) {
            throw new LoyverseException("Could not reach Loyverse: {$e->getMessage()}", previous: $e);
        }

        return $this->decode($path, $response);
    }

    private function decode(string $path, Response $response): array
    {
        if ($response->successful()) {
            return (array) $response->json();
        }

        if (in_array($response->status(), [401, 403], true)) {
            throw new LoyverseAuthException('Loyverse rejected the token.');
        }

        if ($response->status() === 429) {
            /* The account budget is shared — someone else spent it. This is
               worth a loud log line: per docs/LOYVERSE.md it means our slice
               is set too high or another integration is hungry. */
            $retryAfter = max(1, (int) $response->header('Retry-After', '60'));
            Log::warning('Loyverse answered 429 — shared account budget exhausted upstream.', [
                'path' => $path, 'retry_after' => $retryAfter,
            ]);
            throw new LoyverseBudgetExhausted($retryAfter, 'Loyverse rate limit hit for the merchant account.');
        }

        throw new LoyverseException("Loyverse answered {$response->status()} for {$path}.");
    }
}
