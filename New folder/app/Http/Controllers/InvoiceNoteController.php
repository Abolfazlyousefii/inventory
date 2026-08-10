<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class InvoiceNoteController extends Controller
{
    public function store(string $uuid, Request $request)
    {
        $invoice = \App\Models\Invoice::where('uuid',$uuid)->firstOrFail();

        $data = $request->validate([
            'body' => 'nullable|string|max:5000',
        ]);

        if (blank($data['body'] ?? null)) {
            return back()->with('info','یادداشت خالی ثبت نشد.');
        }

        $invoice->notes()->create([
            'user_id' => auth()->id(),
            'body' => $data['body'],
        ]);

        return back()->with('success','✅ یادداشت ثبت شد.');
    }
}
