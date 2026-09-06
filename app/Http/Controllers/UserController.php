<?php

namespace App\Http\Controllers;

use App\DataTables\UserDataTable;
use App\Services\CrmUserService;

class UserController extends Controller {
    public function index( UserDataTable $dataTable ) {
        return $dataTable->render('users.index');
    }

    public function sync( CrmUserService $crmUserService ) {
        $result = $crmUserService->syncUsers(full : false);

        if ( !empty($result['error']) ) {
            return redirect()
                ->route('users.index')
                ->with('sync_error', $result['error']);
        }

        return redirect()
            ->route('users.index')
            ->with('sync_success', sprintf('سینک کاربران با موفقیت انجام شد. تعداد کاربران sync شده: %d | غیرفعال‌شده: %d', $result['synced_count'], $result['deactivated_count'] ?? 0));
    }
}