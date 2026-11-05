<?php

namespace App\Http\Controllers;

use App\Http\Requests\Finance\CalculatePaymentScheduleRequest;
use App\Services\Finance\PaymentScheduleCalculator;
use App\Support\JalaliDate;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class FinancePaymentCalculatorController extends Controller
{
    public function index(): View
    {
        $today = CarbonImmutable::today();
        return view('finance.payment-calculator.index', [
            'todayJalali' => JalaliDate::date($today),
            'firstDueJalali' => JalaliDate::date($today->addDays(30)),
            'secondDueJalali' => JalaliDate::date($today->addDays(60)),
        ]);
    }

    public function calculate(CalculatePaymentScheduleRequest $request, PaymentScheduleCalculator $calculator): JsonResponse
    {
        $data = $request->validated();
        $base = CarbonImmutable::parse(JalaliDate::toGregorianDate($data['base_date']))->startOfDay();
        $dayFor = static function (string $date) use ($base): int {
            $due = CarbonImmutable::parse(JalaliDate::toGregorianDate($date))->startOfDay();
            return (int) $base->diffInDays($due, false);
        };

        $fixed = array_map(static fn (array $item): array => [
            'day' => $dayFor($item['due_date']),
            'amount_rial' => (int) $item['amount_rial'],
        ], $data['fixed_payments'] ?? []);
        $weighted = array_map(static fn (array $item): array => [
            'day' => $dayFor($item['due_date']),
            'weight' => (float) $item['weight'],
        ], $data['weighted_payments'] ?? []);

        try {
            $result = $calculator->calculate(
                (int) $data['price_rial'],
                (float) $data['monthly_percent'] / 100,
                $fixed,
                $weighted,
            );
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['calculation' => $exception->getMessage()]);
        }

        return response()->json(['ok' => true, 'result' => $result]);
    }
}
