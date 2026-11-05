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
        $result = $this->calculateSchedule($request->validated(), $calculator);

        return response()->json(['ok' => true, 'result' => $result]);
    }

    public function printReport(CalculatePaymentScheduleRequest $request, PaymentScheduleCalculator $calculator): View
    {
        $data = $request->validated();
        // Recalculate on the server: never trust amounts previously shown in the browser.
        $result = $this->calculateSchedule($data, $calculator);

        $rows = [];
        foreach ($result['fixed_payments'] as $index => $payment) {
            $input = $data['fixed_payments'][$index];
            $rows[] = [
                'type' => $input['kind'] === 'cheque' ? 'چک با مبلغ مشخص' : 'نقدی / پیش‌پرداخت',
                'due_date' => $input['due_date'],
                'day' => $payment['day'],
                'weight' => null,
                'amount_rial' => $payment['amount_rial'],
                'present_value_rial' => $payment['present_value_rial'],
            ];
        }

        foreach ($result['flexible_payments'] as $index => $payment) {
            $rows[] = [
                'type' => 'چک محاسبه‌شده',
                'due_date' => $data['weighted_payments'][$index]['due_date'],
                'day' => $payment['day'],
                'weight' => $payment['weight'],
                'amount_rial' => $payment['amount_rial'],
                'present_value_rial' => $payment['present_value_rial'],
            ];
        }

        usort($rows, fn (array $a, array $b): int => $a['day'] <=> $b['day']);

        return view('finance.payment-calculator.print', [
            'result' => $result,
            'rows' => $rows,
            'customerName' => $data['customer_name'] ?? '',
            'baseDate' => $data['base_date'],
            'printedAt' => JalaliDate::dateTime(now()),
        ]);
    }

    /** @param array<string, mixed> $data */
    private function calculateSchedule(array $data, PaymentScheduleCalculator $calculator): array
    {
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
            return $calculator->calculate(
                (int) $data['price_rial'],
                (float) $data['monthly_percent'] / 100,
                $fixed,
                $weighted,
            );
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['calculation' => $exception->getMessage()]);
        }
    }
}
