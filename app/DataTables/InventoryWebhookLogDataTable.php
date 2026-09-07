<?php

namespace App\DataTables;

use App\Models\InventoryWebhookLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Yajra\DataTables\Facades\DataTables;

class InventoryWebhookLogDataTable
{
    public function ajax()
    {
        /*
         * Preserve the existing behavior:
         * only the latest 100 webhook log records.
         */
        $latestLogs = DB::table('inventory_webhook_logs')
            ->select('id')
            ->orderByDesc('id')
            ->limit(100);


        $query = InventoryWebhookLog::query()
            ->joinSub(
                $latestLogs,
                'latest_inventory_webhook_logs',
                function ($join) {
                    $join->on(
                        'inventory_webhook_logs.id',
                        '=',
                        'latest_inventory_webhook_logs.id'
                    );
                }
            )
            ->select([
                'inventory_webhook_logs.id',
                'inventory_webhook_logs.event',
                'inventory_webhook_logs.payload',
                'inventory_webhook_logs.status',
                'inventory_webhook_logs.attempts',
                'inventory_webhook_logs.next_retry_at',
                'inventory_webhook_logs.response_code',
                'inventory_webhook_logs.sent_at',
                'inventory_webhook_logs.error_message',
            ])
            ->orderByDesc('inventory_webhook_logs.id');


        return DataTables::eloquent($query)

            ->addColumn('payload_summary', function (InventoryWebhookLog $log) {

                $payloadValue = $log->payload;


                if (is_string($payloadValue)) {

                    $decoded = json_decode($payloadValue, true);

                    $payload = is_array($decoded)
                        ? $decoded
                        : [];

                } else {

                    $payload = (array) ($payloadValue ?? []);

                }


                $variantRows = [];


                if (
                    !empty($payload['variants'])
                    && is_array($payload['variants'])
                ) {

                    $variantRows = $payload['variants'];

                } elseif (
                    !empty($payload['payload']['variants'])
                    && is_array($payload['payload']['variants'])
                ) {

                    $variantRows = $payload['payload']['variants'];

                }


                $html = '';


                if (
                    !empty($payload['payload']['product_id'])
                    || !empty($payload['payload']['sku'])
                    || !empty($payload['payload']['name'])
                ) {

                    $html .= '<div>'
                             . 'کالا: '
                             . e($payload['payload']['name'] ?? '-')
                             . ' (ID: '
                             . e($payload['payload']['product_id'] ?? '-')
                             . ')'
                             . '</div>';

                    $html .= '<div class="small text-muted">'
                             . 'SKU: '
                             . e($payload['payload']['sku'] ?? '-')
                             . '</div>';

                }


                if (!empty($payload['payload']['movement_id'])) {

                    $html .= '<div class="small">'
                             . 'حرکت انبار: #'
                             . e($payload['payload']['movement_id'])
                             . ' | محصول: '
                             . e($payload['payload']['product_id'] ?? '-')
                             . '</div>';

                }


                if (!empty($variantRows)) {

                    $html .= '<div class="small mt-1">تنوع‌ها:';


                    foreach (array_slice($variantRows, 0, 5) as $v) {

                        $html .= '<div class="border rounded p-1 mb-1 bg-light-subtle">';

                        $html .= '<div>'
                                 . 'ID: ' . e($v['id'] ?? '-')
                                 . ' | قیمت: ' . e($v['price'] ?? '-')
                                 . ' | موجودی: ' . e($v['balance'] ?? '-')
                                 . '</div>';

                        $html .= '<div>'
                                 . 'محصول: ' . e($v['product_name'] ?? '-')
                                 . ' | کد محصول: ' . e($v['product_code'] ?? '-')
                                 . '</div>';

                        $html .= '<div>'
                                 . 'تنوع: ' . e($v['variant_name'] ?? '-')
                                 . ' | کد تنوع: ' . e($v['variant_code'] ?? '-')
                                 . '</div>';

                        $html .= '</div>';

                    }


                    $html .= '</div>';

                }


                return $html !== ''
                    ? $html
                    : '-';

            })

            ->editColumn('attempts', function (InventoryWebhookLog $log) {

                return $log->attempts ?? 0;

            })

            ->editColumn('next_retry_at', function (InventoryWebhookLog $log) {

                return $log->next_retry_at
                    ? Carbon::parse($log->next_retry_at)->format('Y-m-d H:i:s')
                    : '-';

            })

            ->editColumn('response_code', function (InventoryWebhookLog $log) {

                return $log->response_code ?? '-';

            })

            ->editColumn('sent_at', function (InventoryWebhookLog $log) {

                return $log->sent_at
                    ? Carbon::parse($log->sent_at)->format('Y-m-d H:i:s')
                    : '-';

            })

            ->editColumn('error_message', function (InventoryWebhookLog $log) {

                return $log->error_message ?? '-';

            })

            ->rawColumns([
                'payload_summary',
            ])

            ->toJson();
    }
}