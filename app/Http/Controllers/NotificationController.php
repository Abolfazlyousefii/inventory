<?php

namespace App\Http\Controllers;

use App\Models\SystemNotification;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class NotificationController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();
        $filter = $this->filter($request);
        $notifications = $this->listing($user, $filter)->paginate(20)->withQueryString();
        $unreadCount = $this->countFor($user);

        return view('notifications.index', compact('notifications', 'filter', 'unreadCount'));
    }

    public function latest(Request $request): JsonResponse
    {
        $user = $request->user();
        $filter = $this->filter($request);
        $perPage = max(1, min(50, $request->integer('per_page', 6)));
        $page = max(1, $request->integer('page', 1));
        $notifications = $this->listing($user, $filter)->paginate($perPage, ['*'], 'page', $page);
        if ($page > $notifications->lastPage()) {
            $notifications = $this->listing($user, $filter)->paginate($perPage, ['*'], 'page', $notifications->lastPage());
        }

        return $this->json([
            'items' => $notifications->getCollection()->map(fn (SystemNotification $notification) => [
                'id' => $notification->id,
                'title' => $notification->title,
                'message' => Str::limit(Str::squish((string) $notification->message), 180),
                'open_url' => route('notifications.open', $notification, false),
                'read_url' => route('notifications.read', $notification, false),
                'read_at' => $notification->readTimestamp()?->toIso8601String(),
                'created_at' => $notification->created_at?->toIso8601String(),
                'created_at_human' => $notification->created_at?->locale('fa')->diffForHumans(),
                'category' => $notification->categoryLabel(),
            ])->values(),
            'count' => $this->countFor($user),
            'total' => $notifications->total(),
            'page' => $notifications->currentPage(),
            'last_page' => $notifications->lastPage(),
        ]);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return $this->json(['count' => $this->countFor($request->user())]);
    }

    public function read(Request $request, SystemNotification $notification): JsonResponse
    {
        $this->markRead($request->user(), $notification);

        return $this->json(['ok' => true, 'count' => $this->countFor($request->user()), 'url' => $this->destination($notification)]);
    }

    public function readAll(Request $request): JsonResponse|RedirectResponse
    {
        SystemNotification::markAllAsReadFor($request->user());

        return $request->expectsJson()
            ? $this->json(['ok' => true, 'count' => $this->countFor($request->user())])
            : redirect()->route('notifications.index');
    }

    public function open(Request $request, SystemNotification $notification): RedirectResponse
    {
        $this->markRead($request->user(), $notification);

        return redirect()->to($this->destination($notification));
    }

    private function markRead(User $user, SystemNotification $notification): void
    {
        abort_unless(SystemNotification::query()->forUser($user)->whereKey($notification->id)->exists(), 403);
        $notification->markAsReadFor($user);
    }

    private function countFor(User $user): int
    {
        return SystemNotification::query()->forUser($user)->unreadFor($user)->count();
    }

    private function filter(Request $request): string
    {
        $filter = $request->query('filter', 'unread');

        return in_array($filter, ['unread', 'read', 'all'], true) ? $filter : 'unread';
    }

    private function listing(User $user, string $filter): Builder
    {
        $query = SystemNotification::query()->forUser($user)->withReadState($user)->newestFirst();

        return match ($filter) {
            'unread' => $query->unreadFor($user),
            'read' => $query->readFor($user),
            default => $query,
        };
    }

    private function destination(SystemNotification $notification): string
    {
        $fallback = route('notifications.index');
        $link = $notification->link;
        if (! $link || preg_match('/[\\\\\x00-\x20\x7f]/', $link)) {
            return $fallback;
        }
        if (str_starts_with($link, '/') && ! str_starts_with($link, '//')) {
            return $link;
        }
        $target = parse_url($link);
        $origin = parse_url(url('/'));
        if ($target && $origin && isset($target['scheme'], $target['host'])
            && in_array(strtolower($target['scheme']), ['http', 'https'], true)
            && strcasecmp($target['scheme'], $origin['scheme']) === 0
            && strcasecmp($target['host'], $origin['host']) === 0
            && ($target['port'] ?? null) === ($origin['port'] ?? null)
            && ! isset($target['user']) && ! isset($target['pass'])) {
            return $link;
        }

        return $fallback;
    }

    private function json(array $payload): JsonResponse
    {
        return response()->json($payload)->header('Cache-Control', 'private, no-store');
    }
}
