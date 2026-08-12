<?php

namespace Tests\Feature\Api;

use App\Models\Expense;
use App\Models\PushSubscription;
use App\Models\Receipt;
use App\Models\User;
use App\Support\ReminderPlanner;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
 * Web-push reminders: the mailbox endpoints, and the planner's rules — what
 * fires, when, on each branch's own clock. Planning is pure, so every rule
 * is asserted here without a push service.
 */
class PushReminderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function manager(): User
    {
        return User::query()->where('username', 'marvin.deocampo')->firstOrFail();
    }

    private function sale(string $day, float $gross = 1000.0): void
    {
        Receipt::query()->create([
            'receipt_number' => 'r-'.$day, 'store_id' => 'arevalo', 'type' => 'SALE',
            'day' => $day, 'hour' => 10, 'receipt_date' => "{$day} 02:00:00",
            'gross' => $gross, 'cost' => 0.0, 'cancelled' => false,
            'loyverse_updated_at' => now(),
        ]);
    }

    /** A UTC instant that is the given hour today on the branch's clock */
    private function manilaAt(int $hour): CarbonImmutable
    {
        return CarbonImmutable::now('Asia/Manila')->setTime($hour, 0)->utc();
    }

    public function test_a_browser_mailbox_is_registered_and_forgotten(): void
    {
        $sub = [
            'endpoint' => 'https://push.example/box-1',
            'keys' => ['p256dh' => 'client-key', 'auth' => 'client-auth'],
        ];

        $this->actingAs($this->manager())
            ->postJson('/api/push/subscriptions', $sub)
            ->assertNoContent();
        $this->assertSame(1, PushSubscription::query()->count());

        // The same browser re-subscribing under another account moves the box
        $owner = User::query()->where('role', 'owner')->firstOrFail();
        $this->actingAs($owner)->postJson('/api/push/subscriptions', $sub)->assertNoContent();
        $this->assertSame((string) $owner->id, (string) PushSubscription::query()->sole()->user_id);

        // Only its current holder may delete it
        $this->actingAs($this->manager())
            ->deleteJson('/api/push/subscriptions', ['endpoint' => $sub['endpoint']])
            ->assertNoContent();
        $this->assertSame(1, PushSubscription::query()->count());

        $this->actingAs($owner)
            ->deleteJson('/api/push/subscriptions', ['endpoint' => $sub['endpoint']])
            ->assertNoContent();
        $this->assertSame(0, PushSubscription::query()->count());
    }

    public function test_the_evening_nudge_fires_only_on_a_sold_unlogged_day(): void
    {
        $planner = app(ReminderPlanner::class);
        $today = CarbonImmutable::now('Asia/Manila')->format('Y-m-d');
        $this->sale($today);

        // Sold, nothing logged, evening → the nudge
        $due = $planner->due($this->manilaAt((int) config('push.expense_hour')));
        $tags = array_column($due, 'tag');
        $this->assertContains("expenses:arevalo:{$today}", $tags);

        // The same state at another hour stays quiet
        $this->assertSame([], array_filter(
            $planner->due($this->manilaAt(14)),
            fn (array $r) => str_starts_with($r['tag'], 'expenses:'),
        ));

        // Logged — quiet again
        Expense::query()->create([
            'store_id' => 'arevalo', 'day' => $today, 'category' => 'Meals',
            'note' => 'lunch', 'amount' => 150.0, 'at' => now(),
        ]);
        $this->assertSame([], array_filter(
            $planner->due($this->manilaAt((int) config('push.expense_hour'))),
            fn (array $r) => str_starts_with($r['tag'], 'expenses:arevalo'),
        ));
    }

    public function test_the_morning_nudge_names_the_deposit_backlog(): void
    {
        $planner = app(ReminderPlanner::class);
        $today = CarbonImmutable::now('Asia/Manila');
        // Four past audited days, none deposited — past the default 3-day window
        foreach ([1, 2, 3, 4] as $back) {
            $this->sale($today->subDays($back)->format('Y-m-d'));
        }

        $due = array_values(array_filter(
            $planner->due($this->manilaAt((int) config('push.deposit_hour'))),
            fn (array $r) => str_starts_with($r['tag'], 'deposits:arevalo'),
        ));
        $this->assertCount(1, $due);
        $this->assertSame('Deposit overdue', $due[0]['title']);
        $this->assertStringContainsString('past the usual 3-day window', $due[0]['body']);

        // One pending day is the normal morning, not a reminder
        Receipt::query()->whereNot('day', $today->subDay()->format('Y-m-d'))->delete();
        $this->assertSame([], array_filter(
            $planner->due($this->manilaAt((int) config('push.deposit_hour'))),
            fn (array $r) => str_starts_with($r['tag'], 'deposits:arevalo'),
        ));
    }
}
