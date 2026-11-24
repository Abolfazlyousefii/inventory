<?php

namespace App\Http\Controllers;

use App\Models\PersonnelAttachment;
use App\Models\PersonnelEntry;
use App\Models\User;
use App\Services\Personnel\PersonnelAccess;
use App\Services\Personnel\PersonnelDates;
use App\Services\Personnel\PersonnelWorkflow;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PersonnelWorkflowController extends Controller
{
    public function __construct(private PersonnelAccess $access, private PersonnelWorkflow $workflow) {}

    private function kind(Request $request): string
    {
        return $request->route('kind');
    }

    private function authorizeUse(Request $request): void
    {
        abort_unless($this->access->canUse($request->user(), $this->kind($request)), 403);
    }

    private function authorizeView(Request $request, PersonnelEntry $entry): void
    {
        abort_unless($entry->kind === $this->kind($request), 404);
        abort_unless($this->access->view($request->user(), $entry), 403);
    }

    public function index(Request $request)
    {
        $kind = $this->kind($request);
        $user = $request->user();
        [$query,$team] = $this->filteredEntries($request);
        $entries = $query->latest('id')->paginate(15)->withQueryString();
        $canUse = $this->access->canUse($user, $kind);
        $canReview = $this->access->canReview($user, $kind);

        return view($request->ajax() ? 'personnel.workflows.list' : 'personnel.workflows.index', compact('kind', 'entries', 'team', 'canUse', 'canReview'));
    }

    private function filteredEntries(Request $request): array
    {
        $kind = $this->kind($request);
        $user = $request->user();
        abort_unless($this->access->canUse($user, $kind) || $this->access->canReview($user, $kind), 403);
        $filters = $request->validate(['tab' => ['nullable', Rule::in(['mine', 'team'])], 'status' => ['nullable', Rule::in(array_keys(PersonnelEntry::STATUSES))], 'q' => 'nullable|string|max:100', 'from' => 'nullable|string|max:10', 'to' => 'nullable|string|max:10']);
        $team = ($filters['tab'] ?? 'mine') === 'team';
        if (! $team && ! $this->access->canUse($user, $kind)) {
            $team = true;
        }
        $query = $this->access->visible(PersonnelEntry::query(), $user, $kind, $team)->with(['user:id,name,personnel_code', 'steps']);
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (filled($filters['q'] ?? null)) {
            $query->where(fn ($q) => $q->where('title', 'like', '%'.$filters['q'].'%')->orWhereHas('user', fn ($q) => $q->where('name', 'like', '%'.$filters['q'].'%')));
        }
        if ($kind === 'leave') {
            $from = filled($filters['from'] ?? null) ? PersonnelDates::parse($filters['from'], 'from') : null;
            $to = filled($filters['to'] ?? null) ? PersonnelDates::parse($filters['to'], 'to') : null;
            if ($from && $to && $from->gt($to)) {
                throw ValidationException::withMessages(['to' => 'تاریخ پایان بازه نباید قبل از شروع آن باشد.']);
            }
            // Include requests intersecting the selected range, including multi-day leave.
            if ($from) {
                $query->whereDate('end_date', '>=', $from);
            }
            if ($to) {
                $query->whereDate('start_date', '<=', $to);
            }
        }

        return [$query, $team];
    }

    public function export(Request $request)
    {
        [$query] = $this->filteredEntries($request);

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['شماره', 'پرسنل', 'کد پرسنلی', 'نوع مرخصی', 'نحوه ثبت', 'از تاریخ شمسی', 'تا تاریخ شمسی', 'از ساعت', 'تا ساعت', 'وضعیت', 'مسئول فعلی', 'علت']);
            foreach ($query->orderBy('id')->lazy(300) as $entry) {
                $cells = [$entry->id, $entry->user?->name, $entry->user?->personnel_code, $entry->payload['leave_type'] ?? '', $entry->payload['leave_unit'] ?? '', PersonnelDates::display($entry->start_date), PersonnelDates::display($entry->end_date), ($entry->payload['leave_unit'] ?? '') === 'ساعتی' ? ($entry->payload['start_time'] ?? '') : '', ($entry->payload['leave_unit'] ?? '') === 'ساعتی' ? ($entry->payload['end_time'] ?? '') : '', $entry->label(), $entry->steps->firstWhere('status', 'pending')?->approver_name, $entry->description];
                fputcsv($out, array_map(static function ($value) {
                    $value = (string) ($value ?? '');

                    return preg_match('/^[\s\x{FEFF}]*[=+@-]/u', $value) ? "'".$value : $value;
                }, $cells));
            }
            fclose($out);
        }, 'leaves-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function printLeaves(Request $request)
    {
        [$query,$team] = $this->filteredEntries($request);
        if ((clone $query)->count() > 1000) {
            throw ValidationException::withMessages(['from' => 'برای چاپ بیش از هزار مورد، بازه تاریخ را محدود کنید یا فایل خروجی بگیرید.']);
        }
        $entries = $query->orderBy('start_date')->orderBy('id')->get();

        return response()->view('personnel.workflows.leave-print', compact('entries', 'team'))->header('Cache-Control', 'private, no-store');
    }

    public function create(Request $request)
    {
        $this->authorizeUse($request);
        $kind = $this->kind($request);
        $entry = null;
        $substitutes = User::activeErpUsers()->where('id', '!=', $request->user()->id)->where('department', $request->user()->department)->orderBy('name')->get(['id', 'name']);

        return view($request->ajax() ? 'personnel.workflows.entry-form' : 'personnel.workflows.form', compact('kind', 'entry', 'substitutes'));
    }

    private function validated(Request $request, bool $create): array
    {
        $common = ['submission_key' => $create ? 'required|uuid' : 'nullable|uuid', 'version' => $create ? 'nullable' : 'required|integer|min:1'];
        if ($this->kind($request) === 'leave') {
            return $request->validate($common + [
                'leave_type' => ['required', Rule::in(['اضطراری', 'استعلاجی', 'استحقاقی', 'ماموریت'])],
                'leave_unit' => ['required', Rule::in(['روزانه', 'ساعتی'])],
                'start_date' => 'required|string|max:10', 'end_date' => 'required|string|max:10',
                'start_time' => 'nullable|string|max:5', 'end_time' => 'nullable|string|max:5',
                'substitute_user_id' => 'nullable|integer|exists:users,id', 'reason' => 'nullable|string|max:4000',
            ]);
        }

        return $request->validate($common + [
            'title' => 'nullable|string|max:255', 'description' => 'required|string|max:30000',
            'successful_calls' => 'nullable|integer|min:0|max:100000', 'unsuccessful_calls' => 'nullable|integer|min:0|max:100000',
            'action' => ['required', Rule::in(['draft', 'submit'])],
            'attachments' => 'nullable|array|max:5', 'attachments.*' => 'file|max:10240|mimes:pdf,jpg,jpeg,png,webp,txt,doc,docx,xls,xlsx,mp3,wav,ogg,webm,m4a,mp4|extensions:pdf,jpg,jpeg,png,webp,txt,doc,docx,xls,xlsx,mp3,wav,ogg,webm,m4a,mp4',
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizeUse($request);
        $data = $this->validated($request, true);
        $paths = [];
        $kind = $this->kind($request);
        $created = false;
        try {
            $entry = DB::transaction(function () use ($request, $data, $kind, &$paths, &$created) {
                $author = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
                $existing = PersonnelEntry::where('submission_key', $data['submission_key'])->first();
                if ($existing) {
                    abort_unless($existing->user_id === $author->id && $existing->kind === $kind, 409);

                    return $existing;
                }
                $fields = $kind === 'leave' ? $this->workflow->leaveData($author, $data) : $this->reportFields($data);
                $entry = PersonnelEntry::create($fields + ['kind' => $kind, 'user_id' => $author->id, 'submission_key' => $data['submission_key'], 'status' => 'draft']);
                if ($kind === 'leave') {
                    $this->workflow->snapshot($entry, $author);
                    $entry->status = 'pending';
                    $entry->submitted_at = now();
                } elseif ($data['action'] === 'submit') {
                    $this->workflow->reportSubmit($entry, $author);
                }
                $entry->save();
                $this->attachments($request, $entry, $paths);
                $this->workflow->event($entry, $author, $entry->status === 'draft' ? 'draft' : 'submitted');
                if ($entry->status !== 'draft') {
                    $this->workflow->notify($entry, $entry->steps()->whereIn('status', ['pending', 'watching'])->pluck('approver_id')->all());
                }
                $created = true;

                return $entry;
            });
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($paths);
            throw $e;
        }

        return $this->saved($request, $entry, $created ? 201 : 200);
    }

    private function reportFields(array $data): array
    {
        if (! filled(trim($data['description'] ?? ''))) {
            throw ValidationException::withMessages(['description' => 'متن گزارش را وارد کنید.']);
        }

        return ['title' => $data['title'] ?? null, 'description' => $data['description'], 'payload' => ['successful_calls' => (int) ($data['successful_calls'] ?? 0), 'unsuccessful_calls' => (int) ($data['unsuccessful_calls'] ?? 0)]];
    }

    private function attachments(Request $request, PersonnelEntry $entry, array &$paths): void
    {
        if ($entry->attachments()->count() + count($request->file('attachments', [])) > 5) {
            throw ValidationException::withMessages(['attachments' => 'هر گزارش حداکثر پنج فایل پیوست دارد.']);
        }
        foreach ($request->file('attachments', []) as $file) {
            $path = $file->store('personnel/'.$entry->id, 'local');
            if (! is_string($path) || ! Storage::disk('local')->exists($path)) {
                throw ValidationException::withMessages(['attachments' => 'ذخیره فایل انجام نشد؛ دوباره تلاش کنید.']);
            }
            $paths[] = $path;
            $entry->attachments()->create(['path' => $path, 'name' => mb_substr(basename(str_replace('\\', '/', $file->getClientOriginalName())), 0, 200), 'mime' => $file->getMimeType(), 'size' => $file->getSize()]);
        }
    }

    private function saved(Request $request, PersonnelEntry $entry, int $status = 200)
    {
        $url = route('personnel.'.($entry->kind === 'leave' ? 'leaves' : 'reports').'.show', $entry->id);
        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'id' => $entry->id, 'version' => $entry->version, 'url' => $url, 'message' => 'اطلاعات با موفقیت ثبت شد.'], $status);
        }

        return redirect($url)->with('success', 'اطلاعات با موفقیت ثبت شد.');
    }

    public function show(Request $request, PersonnelEntry $entry)
    {
        $this->authorizeView($request, $entry);
        $kind = $entry->kind;
        $entry->load(['user', 'steps', 'events.actor:id,name', 'attachments']);
        $canDecide = $kind === 'leave' && $this->access->decide($request->user(), $entry) && $entry->status === 'pending';
        $owner = $entry->user_id === $request->user()->id;
        $canFeedback = $kind === 'report' && ! $owner && $this->access->canReview($request->user(), 'report') && in_array($entry->status, ['submitted', 'read']);

        return view($request->ajax() ? 'personnel.workflows.detail' : 'personnel.workflows.show', compact('entry', 'kind', 'owner', 'canDecide', 'canFeedback'));
    }

    public function edit(Request $request, PersonnelEntry $entry)
    {
        $this->authorizeView($request, $entry);
        abort_unless($entry->kind === 'report' && $entry->user_id === $request->user()->id && in_array($entry->status, ['draft', 'needs_revision']), 403);
        $entry->load('attachments');
        $kind = 'report';
        $substitutes = collect();

        return view($request->ajax() ? 'personnel.workflows.entry-form' : 'personnel.workflows.form', compact('kind', 'entry', 'substitutes'));
    }

    public function update(Request $request, PersonnelEntry $entry)
    {
        $this->authorizeView($request, $entry);
        $this->authorizeUse($request);
        abort_unless($entry->kind === 'report', 404);
        $data = $this->validated($request, false);
        $paths = [];
        try {
            $entry = DB::transaction(function () use ($request, $entry, $data, &$paths) {
                $author = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
                $entry = PersonnelEntry::whereKey($entry->id)->lockForUpdate()->firstOrFail();
                abort_unless($entry->user_id === $author->id && in_array($entry->status, ['draft', 'needs_revision']), 403);
                abort_if($entry->version !== (int) $data['version'], 409, 'گزارش تغییر کرده است؛ دوباره باز کنید.');
                $fields = $this->reportFields($data);
                $fields['payload'] = array_merge($entry->payload ?? [], $fields['payload']);
                $entry->fill($fields);
                $this->attachments($request, $entry, $paths);
                if ($data['action'] === 'submit') {
                    if ($entry->status === 'needs_revision' && hash('sha256', $entry->description) === ($entry->payload['revision_hash'] ?? '')) {
                        throw ValidationException::withMessages(['description' => 'برای ارسال مجدد، متن گزارش را اصلاح کنید.']);
                    }
                    $this->workflow->reportSubmit($entry, $author);
                }
                $entry->version++;
                $entry->save();
                $this->workflow->event($entry, $author, $data['action'] === 'submit' ? 'submitted' : 'draft');
                if ($data['action'] === 'submit') {
                    $this->workflow->notify($entry, $entry->steps()->pluck('approver_id')->all());
                }

                return $entry;
            });
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($paths);
            throw $e;
        }

        return $this->saved($request, $entry);
    }

    public function decision(Request $request, PersonnelEntry $entry)
    {
        $this->authorizeView($request, $entry);
        abort_unless($entry->kind === 'leave', 404);
        $data = $request->validate(['action' => ['required', Rule::in(['approve', 'reject', 'cancel'])], 'version' => 'required|integer|min:1', 'body' => 'nullable|string|max:4000']);
        $entry = $this->workflow->decide($entry, $request->user(), $data['action'], (int) $data['version'], $data['body'] ?? null);

        return $this->saved($request, $entry);
    }

    public function feedback(Request $request, PersonnelEntry $entry)
    {
        $this->authorizeView($request, $entry);
        abort_unless($entry->kind === 'report' && $entry->user_id !== $request->user()->id && $this->access->canReview($request->user(), 'report'), 403);
        $data = $request->validate(['version' => 'required|integer|min:1', 'action' => ['required', Rule::in(['comment', 'read', 'revision'])], 'body' => 'nullable|string|max:4000', 'rating' => 'nullable|integer|min:1|max:5']);
        $entry = DB::transaction(function () use ($request, $entry, $data) {
            $entry = PersonnelEntry::whereKey($entry->id)->lockForUpdate()->firstOrFail();
            $this->authorizeView($request, $entry);
            abort_if($entry->version !== (int) $data['version'], 409, 'اطلاعات جدیدتری ثبت شده؛ جزئیات را دوباره باز کنید.');
            abort_unless(in_array($entry->status, ['submitted', 'read']), 409);
            if ($data['action'] !== 'read' && ! filled(trim($data['body'] ?? ''))) {
                throw ValidationException::withMessages(['body' => 'متن بازخورد را بنویسید.']);
            }
            if ($data['action'] === 'read') {
                $entry->status = 'read';
            }
            if ($data['action'] === 'revision') {
                $entry->status = 'needs_revision';
                $entry->payload = array_merge($entry->payload ?? [], ['revision_hash' => hash('sha256', $entry->description)]);
            }
            $entry->version++;
            $entry->save();
            $this->workflow->event($entry, $request->user(), $data['action'], $data['body'] ?? null, ['rating' => $data['rating'] ?? null]);
            $this->workflow->notify($entry, [$entry->user_id]);

            return $entry;
        });

        return $this->saved($request, $entry);
    }

    public function download(Request $request, PersonnelAttachment $attachment)
    {
        abort_unless($this->access->view($request->user(), $attachment->entry), 403);
        abort_unless(Storage::disk('local')->exists($attachment->path), 404);

        return Storage::disk('local')->download($attachment->path, $attachment->name, ['X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }
}
