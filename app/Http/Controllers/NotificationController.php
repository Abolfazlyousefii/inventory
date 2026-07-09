<?php

namespace App\Http\Controllers;

use App\Models\SystemNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request)
    {
        $filter = (string) $request->query('filter', 'all');
        $query = SystemNotification::query()->forUser($request->user());
        if ($filter === 'unread') $query->unread();
        if ($filter === 'read') $query->whereNotNull('read_at');

        $notifications = $query->latest('id')->paginate(20)->withQueryString();
        return view('notifications.index', compact('notifications', 'filter'));
    }

    public function latest(Request $request): JsonResponse
    {
        $items = SystemNotification::query()->forUser($request->user())->latest('id')->limit(10)->get();
        return response()->json($items->map(fn (SystemNotification $notification) => [
            'id' => $notification->id,
            'title' => $notification->title,
            'message' => $notification->message,
            'url' => $notification->link,
            'open_url' => route('notifications.open', $notification),
            'read_at' => $notification->read_at,
            'created_at' => optional($notification->created_at)->toIso8601String(),
            'created_at_human' => optional($notification->created_at)->diffForHumans(),
            'type' => $notification->type,
            'priority' => $notification->priority ?: 'normal',
        ]));
    }

    public function unreadCount(Request $request): JsonResponse
    {
        $count = SystemNotification::query()->forUser($request->user())->unread()->count();
        return response()->json(['count' => $count]);
    }

    public function read(Request $request, SystemNotification $notification): JsonResponse
    {
        abort_unless(SystemNotification::query()->forUser($request->user())->whereKey($notification->id)->exists(), 403);
        $notification->markAsRead();
        return response()->json(['ok' => true]);
    }

    public function readAll(Request $request): JsonResponse
    {
        SystemNotification::query()->forUser($request->user())->unread()->update(['read_at' => now()]);
        return response()->json(['ok' => true]);
    }

    public function open(Request $request, SystemNotification $notification): RedirectResponse
    {
        abort_unless(SystemNotification::query()->forUser($request->user())->whereKey($notification->id)->exists(), 403);
        $notification->markAsRead();
        return redirect()->to($notification->link ?: route('notifications.index'));
    }
}
