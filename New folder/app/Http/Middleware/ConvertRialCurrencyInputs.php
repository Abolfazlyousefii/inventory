<?php

namespace App\Http\Middleware;

use App\Support\Currency;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ConvertRialCurrencyInputs
{
    private const MONEY_KEYS = [
        'amount',
        'cheque_amount',
        'price',
        'buy_price',
        'sell_price',
        'shipping_price',
        'total_price',
        'min_price',
        'max_price',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if (!$request->isMethod('GET') && !$request->expectsJson()) {
            $request->merge($this->convertArray($request->all()));
        }

        if ($request->isMethod('GET')) {
            $request->query->replace($this->convertArray($request->query->all()));
        }

        return $next($request);
    }

    private function convertArray(array $payload): array
    {
        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = $this->convertArray($value);
                continue;
            }

            if ($this->isMoneyKey((string) $key, $payload)) {
                $payload[$key] = Currency::rialInput($value);
            }
        }

        return $payload;
    }

    private function isMoneyKey(string $key, array $siblings): bool
    {
        if (in_array($key, self::MONEY_KEYS, true)) {
            return true;
        }

        if ($key === 'discount_amount') {
            return true;
        }

        if ($key === 'invoice_discount_value') {
            return (string) ($siblings['invoice_discount_type'] ?? $siblings['discount_type'] ?? '') === 'amount';
        }

        if ($key === 'discount_value') {
            $type = (string) ($siblings['discount_type'] ?? $siblings['invoice_discount_type'] ?? '');
            return $type === 'amount';
        }

        return false;
    }
}
