<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\RunBugInvestigationJob;
use App\Models\BugCase;
use Illuminate\Http\Request;

class BugInvestigatorController extends Controller
{
    public function index()
    {
        $bugCases = BugCase::with('report', 'creator')->latest()->paginate(20);

        return view('bug-investigator.index', compact('bugCases'));
    }

    public function create()
    {
        return view('bug-investigator.create');
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'title' => 'nullable|string|max:255',
            'description' => 'required|string',
            'module' => 'nullable|string|max:50',
            'entity_type' => 'nullable|string|max:100',
            'entity_id' => 'nullable|integer|min:1',
            'severity' => 'nullable|string|max:50',
        ]);
        $data['created_by'] = $request->user()?->id;

        $case = BugCase::create($data);
        RunBugInvestigationJob::dispatch($case);

        return redirect()
            ->route('admin.bug-investigator.show', $case)
            ->with('success', 'پرونده بررسی باگ ثبت شد.');
    }

    public function show(BugCase $bugCase)
    {
        $bugCase->load('report', 'creator');

        return view('bug-investigator.show', compact('bugCase'));
    }

    public function rerun(BugCase $bugCase)
    {
        if (! in_array($bugCase->status, ['pending', 'failed'], true)) {
            return redirect()
                ->route('admin.bug-investigator.show', $bugCase)
                ->with('error', 'این پرونده فقط در وضعیت pending یا failed قابل اجرای مجدد است.');
        }

        $bugCase->update([
            'status' => 'pending',
            'error_message' => null,
        ]);

        RunBugInvestigationJob::dispatch($bugCase->fresh());

        return redirect()
            ->route('admin.bug-investigator.show', $bugCase)
            ->with('success', 'بررسی باگ دوباره در صف اجرا قرار گرفت. مطمئن شوید queue worker در حال اجراست.');
    }
}
