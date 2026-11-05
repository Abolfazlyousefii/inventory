<?php

if (PHP_SAPI !== 'cli') {
    exit(1);
}
$file = dirname(__DIR__, 2).'/storage/logs/hall-browser-state.json';
$action = $argv[1] ?? 'reset';
if ($action !== 'reset') {
    $data = json_decode(file_get_contents($file), true);
    if ($action === 'move') {
        foreach ($data['documents'] as &$doc) {
            if ($doc['key'] === 'order:6') {
                $doc['stage'] = 'warehouse';
                $doc['stage_seconds'] = 0;
                $doc['entry_token'] = 'fixture:'.$doc['key'].':warehouse:'.date('His');
                $doc['status_label'] = 'در انتظار جمع‌آوری';
            }
        }
        unset($doc);
        foreach ($data['stages'] as &$stage) {
            $stage['count'] = count(array_filter($data['documents'], fn ($doc) => $doc['stage'] === $stage['key']));
        }
        unset($stage);
    } elseif ($action === 'empty') {
        $data['documents'] = [];
        foreach ($data['stages'] as &$stage) {
            $stage['count'] = 0;
        }
        unset($stage);
    } else {
        $data['test_mode'] = $action === 'live' ? '' : $action;
    }
} else {
    $data = ['version' => 3, 'refresh_seconds' => 5, 'updated_at' => date(DATE_ATOM),
        'metrics' => ['sales_rial' => 12840000000, 'sales_count' => 48, 'cash_rial' => 8460000000, 'cheque_rial' => 3200000000, 'cheque_count' => 12, 'month_invoice_count' => 78, 'month_jalali' => '1405/08'],
        'summary' => ['total' => 34], 'stages' => [], 'documents' => [], 'events' => []];
    $id = 0;
    foreach (['reserved' => ['رزرو فعال', 5], 'finance' => ['صف مالی', 9], 'warehouse' => ['صف انبار', 8], 'reapproval' => ['تأیید مجدد مالی', 5], 'shipping' => ['صف ارسال', 7]] as $key => [$label, $count]) {
        $data['stages'][] = ['key' => $key, 'label' => $label, 'count' => $count, 'warning_minutes' => 45];
        for ($i = 0; $i < $count; $i++) {
            $id++;
            $data['documents'][] = ['key' => 'order:'.$id, 'type' => $key === 'reserved' || $key === 'finance' ? 'preinvoice' : 'invoice',
                'number' => (string) (4500 + $id), 'stage' => $key, 'status' => $key, 'status_label' => $label,
                'customer' => ['موبایل پارسا', 'فروشگاه آوا', 'تجارت نوین', 'پخش نگین', 'دیجیتال باران'][$i % 5],
                'seller' => 'فروشندهٔ نمونه', 'amount_rial' => 122590000 + $i * 12000000, 'item_count' => 4 + $i * 3,
                'unit_count' => 12 + $i * 20, 'entry_token' => 'fixture:'.$id.':'.$key, 'transfer_count' => $i, 'stage_time_known' => true, 'stage_seconds' => 420 + $i * 420, 'total_seconds' => 1800 + $i * 900];
        }
    }
}
file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
echo "Synthetic browser state: $action\n";
