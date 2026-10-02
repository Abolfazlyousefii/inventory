<?php

namespace App\Exceptions;

use Illuminate\Http\Exceptions\HttpResponseException;

class PreinvoiceItemStockException extends HttpResponseException
{
    /**
     * @param  array  $suggestedItems  Full item list with short lines lowered to max_allowed (remove=true at zero).
     * @param  array  $messages  Per-item messages for the error bag; the count summary is used when empty.
     */
    public function __construct(array $itemErrors, array $suggestedItems = [], array $messages = [])
    {
        $count = count($itemErrors);
        $message = $count > 0
            ? $count . ' قلم نیاز به اصلاح موجودی دارند.'
            : 'برخی اقلام موجودی آزاد کافی ندارند.';

        $payload = [
            'message' => $message,
            'item_errors' => array_values($itemErrors),
            'suggested_items' => array_values($suggestedItems),
        ];

        if (count($messages) === 1) {
            // Same top-level JSON message a single-message ValidationException would give.
            $payload['message'] = (string) array_values($messages)[0];
        }

        $response = request()->expectsJson()
            ? response()->json($messages === [] ? $payload : $payload + ['errors' => ['products' => array_values($messages)]], 422)
            : redirect()->back()->withInput()
                ->withErrors(['products' => $messages === [] ? $message : array_values($messages)])
                ->with('preinvoice_item_errors', $payload['item_errors'])
                ->with('preinvoice_suggested_items', $payload['suggested_items']);

        parent::__construct($response);
    }
}
