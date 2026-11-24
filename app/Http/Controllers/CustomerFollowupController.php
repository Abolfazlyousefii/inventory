<?php

namespace App\Http\Controllers;

use App\Services\CustomerFollowups;
use App\Services\CustomerWorkspace;
use Illuminate\Http\Request;

class CustomerFollowupController extends Controller
{
    public function __construct(private readonly CustomerFollowups $followups, private readonly CustomerWorkspace $workspace) {}

    public function store(Request $request, int $customer)
    {
        $this->workspace->customer($request->user(), $customer);
        foreach (['body', 'followup_title', 'due_date', 'due_time'] as $key) {
            if (is_string($request->input($key))) {
                $request->merge([$key => trim($request->input($key))]);
            }
        }
        $data = $request->validate(['request_key' => 'required|uuid', 'kind' => 'required|in:call,message,visit,note', 'body' => 'required|string|max:3000', 'followup_title' => 'nullable|string|max:200', 'due_date' => 'nullable|required_with:followup_title|string|max:10', 'due_time' => 'nullable|required_with:followup_title|date_format:H:i']);
        [$note,$created] = $this->followups->record($request->user(), $customer, $data);

        return response()->json(['ok' => true, 'message' => 'ارتباط مشتری ثبت شد.', 'id' => $note->id], $created ? 201 : 200)->header('Cache-Control', 'private, no-store');
    }

    public function update(Request $request, int $customer, int $followup)
    {
        $this->workspace->customer($request->user(), $customer);
        if (is_string($request->input('result'))) {
            $request->merge(['result' => trim($request->input('result'))]);
        }
        $data = $request->validate(['version' => 'required|integer|min:0', 'status' => 'required|in:done,cancelled', 'result' => 'nullable|required_if:status,cancelled|string|max:1500']);
        $this->followups->close($request->user(), $customer, $followup, $data);

        return response()->json(['ok' => true, 'message' => 'وضعیت پیگیری ثبت شد.'])->header('Cache-Control', 'private, no-store');
    }

    public function index(Request $request)
    {
        $data = $request->validate(['scope' => 'nullable|in:today,overdue,upcoming,all,closed', 'page' => 'nullable|integer|min:1']);
        $scope = $data['scope'] ?? 'today';
        $tasks = $this->followups->listing($request->user(), $scope)->orderBy('due_at')->orderBy('id')->paginate(15)->withQueryString();
        $counts = collect(['today', 'overdue', 'upcoming'])->mapWithKeys(fn ($s) => [$s => $this->followups->listing($request->user(), $s)->count()])->all();

        return response()->view($request->ajax() ? 'commercial.customer-workspace.followup-list' : 'commercial.customer-workspace.followups', compact('tasks', 'scope', 'counts'))->header('Cache-Control','private, no-store');
    }
}
