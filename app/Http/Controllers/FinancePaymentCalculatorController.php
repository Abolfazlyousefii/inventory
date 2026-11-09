<?php

namespace App\Http\Controllers;

use App\Http\Requests\Finance\CalculatePaymentScheduleRequest;
use App\Services\Finance\PaymentScheduleCalculator;
use App\Services\Finance\PaymentScenarioCalculator;
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
            'thirdDueJalali' => JalaliDate::date($today->addDays(90)),
        ]);
    }

    public function calculate(CalculatePaymentScheduleRequest $request, PaymentScheduleCalculator $calculator, PaymentScenarioCalculator $scenarios): JsonResponse
    {
        $result = $this->calculateSchedule($request->validated(), $calculator, $scenarios);

        return response()->json(['ok' => true, 'result' => $result]);
    }

    public function printReport(CalculatePaymentScheduleRequest $request, PaymentScheduleCalculator $calculator, PaymentScenarioCalculator $scenarios): View
    {
        $data = $request->validated();
        // Recalculate on the server: never trust amounts previously shown in the browser.
        $result = $this->calculateSchedule($data, $calculator, $scenarios);

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

        foreach ($result['computed_payments'] ?? [] as $payment) {
            $rows[] = [
                'type' => 'نقدی لازم امروز (محاسبه‌شده)',
                'due_date' => $data['base_date'],
                'day' => $payment['day'],
                'weight' => null,
                'amount_rial' => $payment['amount_rial'],
                'present_value_rial' => $payment['present_value_rial'],
            ];
        }

        usort($rows, fn (array $a, array $b): int => $a['day'] <=> $b['day']);

        return view('finance.payment-calculator.print', [
            'result' => $result,
            'mode' => $result['mode'],
            'scenarioTitle' => match ($result['mode']) {
                'cash_needed' => 'محاسبه نقدی لازم برای تکمیل خرید',
                'cash_value' => 'محاسبه ارزش نقدی چک‌های معلوم',
                default => 'محاسبه برنامه نقد و چک',
            },
            'answerTitle' => match ($result['mode']) {
                'cash_needed' => 'نقدی لازم امروز',
                'cash_value' => 'ارزش نقدی امروز',
                default => 'مجموع مبالغ پرداخت',
            },
            'answerAmount' => match ($result['mode']) {
                'cash_needed' => $result['calculated_cash_rial'],
                'cash_value' => $result['cash_equivalent_rial'],
                default => $result['total_nominal_rial'],
            },
            'rows' => $rows,
            'baseDate' => $data['base_date'],
            'printedAt' => JalaliDate::dateTime(now()),
        ]);
    }

    /** @param array<string, mixed> $data */
    private function calculateSchedule(
        array $data,
        PaymentScheduleCalculator $calculator,
        PaymentScenarioCalculator $scenarios,
    ): array {
        $mode = $data['mode'] ?? 'schedule';
        if ($mode !== 'schedule' && count($data['weighted_payments'] ?? []) > 0) {
            throw ValidationException::withMessages([
                'weighted_payments' => 'برای این نوع محاسبه، فقط مبالغ معلوم را وارد کنید؛ چک وزنی کاربرد ندارد.',
            ]);
        }

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
            if ($mode === 'schedule') {
                $result = $calculator->calculate(
                    (int) $data['price_rial'],
                    (float) $data['monthly_percent'] / 100,
                    $fixed,
                    $weighted,
                );
                $result['computed_payments'] = [];
                $result['calculated_cash_rial'] = 0;
                $result['cash_equivalent_rial'] = 0;
            } else {
                $result = $scenarios->calculate(
                    $mode,
                    isset($data['price_rial']) ? (int) $data['price_rial'] : null,
                    (float) $data['monthly_percent'] / 100,
                    $fixed,
                );
            }

            $totalCheques = $result['flexible_total_rial'];
            foreach ($data['fixed_payments'] ?? [] as $item) {
                if ($item['kind'] === 'cheque') {
                    $totalCheques += (int) $item['amount_rial'];
                }
            }
            $result['total_cheques_rial'] = $totalCheques;
            $result['mode'] = $mode;

            return $result;
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['calculation' => $exception->getMessage()]);
        }
    }
}
