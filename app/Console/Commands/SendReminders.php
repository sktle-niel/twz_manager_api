<?php

namespace App\Console\Commands;

use App\Models\PushSubscription;
use App\Models\User;
use App\Support\ReminderPlanner;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

/*
 * Sends the due reminders to each branch manager's subscribed browsers.
 * Scheduled hourly; the planner decides what is due, the cache makes each
 * reminder fire once per day however often the schedule runs, and a mailbox
 * the push service reports gone is deleted on the spot.
 */
class SendReminders extends Command
{
    protected $signature = 'twz:send-reminders';

    protected $description = 'Push the due expense and deposit reminders to branch managers';

    public function handle(ReminderPlanner $planner): int
    {
        if ((string) config('push.private_key') === '') {
            $this->warn('No VAPID keys configured; nothing sent.');

            return self::SUCCESS;
        }

        $due = $planner->due(CarbonImmutable::now('UTC'));
        if ($due === []) {
            $this->info('Nothing due.');

            return self::SUCCESS;
        }

        $push = new WebPush([
            'VAPID' => [
                'subject' => (string) config('push.subject'),
                'publicKey' => (string) config('push.public_key'),
                'privateKey' => (string) config('push.private_key'),
            ],
        ]);

        $sent = 0;
        foreach ($due as $reminder) {
            // Once per day per reminder, however often the schedule fires
            if (! Cache::add("push:sent:{$reminder['tag']}", 1, 72_000)) {
                continue;
            }

            $managerIds = User::query()
                ->where('role', User::ROLE_MANAGER)
                ->where('store_id', $reminder['storeId'])
                ->pluck('id');
            $boxes = PushSubscription::query()->whereIn('user_id', $managerIds)->get();

            foreach ($boxes as $box) {
                $push->queueNotification(
                    Subscription::create([
                        'endpoint' => $box->endpoint,
                        'keys' => ['p256dh' => $box->p256dh, 'auth' => $box->auth],
                    ]),
                    (string) json_encode([
                        'title' => $reminder['title'],
                        'body' => $reminder['body'],
                        'url' => $reminder['url'],
                        'tag' => $reminder['tag'],
                    ]),
                );
            }
        }

        foreach ($push->flush() as $report) {
            if ($report->isSuccess()) {
                $sent++;

                continue;
            }
            /* A mailbox the service says is gone will never work again —
               keeping it just makes every later run fail the same way */
            if ($report->isSubscriptionExpired()) {
                PushSubscription::query()->where('endpoint', $report->getEndpoint())->delete();
            }
        }

        $this->info(sprintf('%d reminder(s) due, %d push(es) delivered.', count($due), $sent));

        return self::SUCCESS;
    }
}
