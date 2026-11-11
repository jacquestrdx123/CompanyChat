<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PushSubscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Web Push subscriptions: one per browser/device that turned background notifications on. */
class PushSubscriptionController extends Controller
{
    /** The VAPID public key the browser needs for pushManager.subscribe(). */
    public function publicKey(): JsonResponse
    {
        return response()->json(['public_key' => config('services.webpush.public_key')]);
    }

    /**
     * Subscribe this browser: { endpoint, keys: { p256dh, auth }, show_preview?, user_agent? }.
     * A browser endpoint is unique, so if someone else signed in on the same browser before,
     * the subscription moves to the current user.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'endpoint' => ['required', 'string', 'max:2000', 'starts_with:https://'],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
            'show_preview' => ['nullable', 'boolean'],
            'user_agent' => ['nullable', 'string'],
        ]);

        PushSubscription::query()->updateOrCreate(
            ['endpoint_hash' => PushSubscription::hashEndpoint($data['endpoint'])],
            [
                'user_id' => $request->user()->id,
                'endpoint' => $data['endpoint'],
                'p256dh' => $data['keys']['p256dh'],
                'auth' => $data['keys']['auth'],
                'show_preview' => $data['show_preview'] ?? true,
                'user_agent' => isset($data['user_agent']) ? mb_substr($data['user_agent'], 0, 300) : null,
            ],
        );

        return response()->json(['ok' => true]);
    }

    /** Change the preview setting: { endpoint, show_preview }. */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'endpoint' => ['required', 'string', 'max:2000'],
            'show_preview' => ['required', 'boolean'],
        ]);
        $this->own($request, $data['endpoint'])->update(['show_preview' => $data['show_preview']]);

        return response()->json(['ok' => true]);
    }

    /** Unsubscribe this browser: { endpoint }. */
    public function destroy(Request $request): JsonResponse
    {
        $data = $request->validate(['endpoint' => ['required', 'string', 'max:2000']]);
        $this->own($request, $data['endpoint'])->delete();

        return response()->json(['ok' => true]);
    }

    private function own(Request $request, string $endpoint)
    {
        return PushSubscription::query()
            ->where('user_id', $request->user()->id)
            ->where('endpoint_hash', PushSubscription::hashEndpoint($endpoint));
    }
}
