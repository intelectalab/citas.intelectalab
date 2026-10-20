<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserNotification;
use App\Support\Notifier;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        if (!$user->tenant_id || !Notifier::available()) {
            return response()->json(['unread' => 0, 'total' => 0, 'items' => []]);
        }

        $validated = $request->validate([
            'unread' => 'nullable|boolean',
            'limit'  => 'nullable|integer|min:1|max:300',
        ]);

        if ($user->tenant) {
            Notifier::upcomingAbsences($user->tenant);
            \App\Support\Confirmations::process($user->tenant);
            \App\Support\Messaging::generate($user->tenant);

            if (\App\Support\Messaging::ready() && \App\Support\Messaging::due($user->tenant_id)->exists()) {
                \App\Support\Messaging::kick($user->tenant_id);
            }
        }

        $mine = fn () => UserNotification::where('tenant_id', $user->tenant_id)->where('user_id', $user->id);
        $timezone = $user->tenant?->timezone ?: 'America/Lima';

        $items = $mine()
            ->when($request->boolean('unread'), fn ($q) => $q->whereNull('read_at'))
            ->orderByDesc('created_at')
            ->limit($validated['limit'] ?? 30)
            ->get()
            ->map(fn (UserNotification $n) => [
                'id'          => $n->id,
                'type'        => $n->type,
                'title'       => $n->title,
                'body'        => $n->body,
                'link'        => $n->link,
                'entity_type' => $n->entity_type,
                'entity_id'   => $n->entity_id,
                'read'        => $n->read_at !== null,
                'created_at'  => $n->created_at->copy()->setTimezone($timezone)->format('Y-m-d\TH:i'),
                'seconds_ago' => (int) $n->created_at->diffInSeconds(now(), true),
            ]);

        return response()->json([
            'unread' => $mine()->whereNull('read_at')->count(),
            'total'  => $mine()->count(),
            'items'  => $items,
        ]);
    }

    public function read(Request $request, string $notification)
    {
        $user = $request->user();

        UserNotification::where('tenant_id', $user->tenant_id)
            ->where('user_id', $user->id)
            ->whereNull('read_at')
            ->findOrFail($notification)
            ->update(['read_at' => now()]);

        return response()->json(['ok' => true]);
    }

    public function readAll(Request $request)
    {
        $user = $request->user();

        if ($user->tenant_id && Notifier::available()) {
            UserNotification::where('tenant_id', $user->tenant_id)
                ->where('user_id', $user->id)
                ->whereNull('read_at')
                ->update(['read_at' => now()]);
        }

        return response()->json(['ok' => true]);
    }
}
