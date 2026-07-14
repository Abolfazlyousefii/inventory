<?php

namespace App\Exceptions;

use Illuminate\Http\Exceptions\HttpResponseException;

class PreinvoiceItemStockException extends HttpResponseException
{
    public function __construct(array $itemErrors)
    {
        $count = count($itemErrors);
        $message = $count > 0
            ? $count . ' قلم نیاز به اصلاح موجودی دارند.'
            : 'برخی اقلام موجودی آزاد کافی ندارند.';

        $payload = [
            'message' => $message,
            'item_errors' => array_values($itemErrors),
        ];

        $response = request()->expectsJson()
            ? response()->json($payload, 422)
            : redirect()->back()->withInput()->withErrors(['products' => $message])->with('preinvoice_item_errors', $payload['item_errors']);

        parent::__construct($response);
    }
}
