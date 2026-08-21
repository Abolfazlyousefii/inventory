<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Invoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RecoveryImportController extends Controller
{
    public function index()
    {
        return view('recovery.index');
    }

    public function import(Request $request)
    {
        $request->validate(['file'=>'required|file']);

        $data = json_decode(file_get_contents($request->file('file')->getRealPath()), true);

        $result = [];

        DB::transaction(function() use ($data, &$result) {
            foreach(($data['invoices'] ?? []) as $invoiceData){

                $number = $invoiceData['uuid'];

                if(Invoice::where('uuid',$number)->exists()){
                    $number = $this->newNumber();
                }

                $invoice = Invoice::create([
                    'uuid'=>$number,
                    'customer_id'=>$invoiceData['customer_id'] ?? null,
                    'customer_name'=>$invoiceData['customer_name'] ?? null,
                    'customer_mobile'=>$invoiceData['customer_mobile'] ?? null,
                    'total'=>$invoiceData['total'] ?? 0,
                    'subtotal'=>$invoiceData['subtotal'] ?? 0,
                    'status'=>$invoiceData['status'] ?? 'pending_collection',
                    'document_date'=>$invoiceData['document_date'] ?? now(),
                ]);

                foreach(($invoiceData['items'] ?? []) as $item){
                    $item['invoice_id']=$invoice->id;
                    unset($item['id']);
                    DB::table('invoice_items')->insert($item);
                }

                $result[]=['old'=>$invoiceData['uuid'],'new'=>$number];
            }
        });

        return back()->with('success','Imported '.count($result).' invoices');
    }

    private function newNumber()
    {
        return str_pad(
            ((int)Invoice::max(DB::raw('CAST(uuid AS UNSIGNED)')))+1,
            5,
            '0',
            STR_PAD_LEFT
        );
    }
}
