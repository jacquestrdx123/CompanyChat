<?php

namespace App\Jobs;

use App\Models\Message;
use App\Models\PushSubscription;
use App\Models\User;
use App\Support\PushMessage;
use App\Support\PushTargets;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

/**
 * Web Push for a new message (replaces the send-push Edge Function + Database Webhook).
 * Dispatched after the message is committed; runs on the queue.
 */
class SendMessagePush implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public string $messageId) {}

    public function handle(): void
    {
        $config = config('services.webpush');
        if (empty($config['public_key']) || empty($config['private_key']) || empty($config['subject'])) {
            return; // push is not configured
        }

        $message = Message::with('channel')->find($this->messageId);
        if (! $message) {
            return; // deleted before we got here
        }

        $targets = PushTargets::for($message);
        if (! $targets) {
            return;
        }
        $subs = PushSubscription::query()->whereIn('user_id', array_keys($targets))->get();
        if ($subs->isEmpty()) {
            return;
        }

        $names = User::query()->pluck('display_name', 'id');
        $nameOf = fn (string $id) => $names[$id] ?? 'Unknown';
        $sender = $message->user_id ? $nameOf($message->user_id) : 'Someone';

        $webPush = new WebPush(['VAPID' => [
            'subject' => $config['subject'],
            'publicKey' => $config['public_key'],
            'privateKey' => $config['private_key'],
        ]], ['TTL' => 3600, 'urgency' => 'high']);

        foreach ($subs as $sub) {
            $note = PushMessage::build(
                $targets[$sub->user_id],
                $message->channel_id,
                $message->channel->kind->value,
                $message->channel->name,
                $sender,
                $message->body,
                $sub->show_preview,
                $nameOf,
            );
            $webPush->queueNotification(
                Subscription::create(['endpoint' => $sub->endpoint, 'keys' => ['p256dh' => $sub->p256dh, 'auth' => $sub->auth]]),
                json_encode($note),
            );
        }

        $expired = [];
        foreach ($webPush->flush() as $report) {
            if ($report->isSuccess()) {
                continue;
            }
            if ($report->isSubscriptionExpired()) {
                $expired[] = PushSubscription::hashEndpoint($report->getEndpoint()); // browser unsubscribed: forget it
            } else {
                Log::warning('push failed', ['reason' => $report->getReason()]);
            }
        }
        if ($expired) {
            PushSubscription::query()->whereIn('endpoint_hash', $expired)->delete();
        }
    }
}
