<?php

namespace App\Services;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class StocktakeRequestService
{
    public function run(Request $request, string $operation, callable $callback): JsonResponse
    {
        $id = $request->input('request_id') ?? $request->header('Idempotency-Key');
        if ($id === null) return $callback(); // Existing open forms remain compatible.
        Validator::make(['request_id' => $id], ['request_id' => 'required|uuid'])->validate();
        abort_if($request->input('request_id') && $request->header('Idempotency-Key')
            && $request->input('request_id') !== $request->header('Idempotency-Key'), 409, 'شناسهٔ درخواست یکسان نیست.');
        $body = $request->except(['_token', 'request_id']);
        // Hash the original client body, before automatic date assignment (including midnight retries).
        $hash = hash('sha256', json_encode($this->canonical($body), JSON_THROW_ON_ERROR));
        $userId = (int) $request->user()->id;

        return DB::transaction(function () use ($id, $operation, $hash, $userId, $callback) {
            DB::table('stocktake_requests')->insertOrIgnore([
                'user_id' => $userId, 'operation' => $operation, 'request_id' => $id,
                'payload_hash' => $hash, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $row = DB::table('stocktake_requests')->where([
                'user_id' => $userId, 'operation' => $operation, 'request_id' => $id,
            ])->lockForUpdate()->first();
            abort_unless($row && hash_equals($row->payload_hash, $hash), 409,
                'این شناسه قبلاً برای شمارش متفاوت استفاده شده است؛ درخواست قبلی را بازیابی کنید.');
            if ($row->response !== null) return response()->json(json_decode($row->response, true, 512, JSON_THROW_ON_ERROR), $row->response_status)
                ->header('Cache-Control', 'private, no-store')->header('Idempotent-Replay', 'true');
            $response = $callback();
            if ($response->isSuccessful()) {
                DB::table('stocktake_requests')->where('id', $row->id)->update([
                    'response' => $response->getContent(), 'response_status' => $response->getStatusCode(), 'updated_at' => now(),
                ]);
            } else {
                DB::table('stocktake_requests')->where('id', $row->id)->delete();
            }
            return $response;
        }, 3);
    }

    public function status(Request $request, string $id): JsonResponse
    {
        $data = Validator::make(['request_id' => $id, 'operation' => $request->query('operation')], [
            'request_id' => 'required|uuid', 'operation' => 'required|in:drafts,finalize,unified_save,unified_finalize',
        ])->validate();
        $row = DB::table('stocktake_requests')->where('user_id', $request->user()->id)
            ->where('operation', $data['operation'])->where('request_id', $id)->whereNotNull('response')->first();
        abort_unless($row, 404);
        return response()->json(json_decode($row->response, true, 512, JSON_THROW_ON_ERROR), $row->response_status)
            ->header('Cache-Control', 'private, no-store');
    }

    private function canonical(mixed $value): mixed
    {
        if (! is_array($value)) return $value;
        if (! array_is_list($value)) ksort($value);
        foreach ($value as $key => $item) $value[$key] = $this->canonical($item);
        return $value;
    }
}
