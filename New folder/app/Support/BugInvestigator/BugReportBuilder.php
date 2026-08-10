<?php

namespace App\Support\BugInvestigator;

use App\Models\BugCase;

class BugReportBuilder
{
    public function build(BugCase $case, array $result): string
    {
        $lines = [];
        $lines[] = '# خلاصه بررسی';
        $lines[] = $result['summary'] ?? 'گزارش بدون خلاصه است.';
        $lines[] = '';
        $lines[] = '# توضیح باگ ثبت‌شده';
        $lines[] = $case->description;
        $lines[] = '';
        $lines[] = '# بخش تشخیص داده‌شده';
        $lines[] = (string) ($result['module'] ?? $case->module ?? 'unknown');
        $lines[] = '';
        $lines[] = '# رکوردهای بررسی‌شده';
        foreach (($result['current_state'] ?? []) as $key => $value) $lines[] = '- '.$key.': '.json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $lines[] = '';
        $lines[] = '# قوانین شکسته‌شده';
        foreach (($result['broken_rules'] ?? []) ?: ['مورد قطعی پیدا نشد.'] as $rule) $lines[] = '- '.(is_array($rule) ? json_encode($rule, JSON_UNESCAPED_UNICODE) : $rule);
        $lines[] = '';
        $lines[] = '# شواهد پیدا شده';
        foreach (($result['findings'] ?? []) ?: ['شاهد خاصی پیدا نشد.'] as $finding) $lines[] = '- '.(is_array($finding) ? json_encode($finding, JSON_UNESCAPED_UNICODE) : $finding);
        $lines[] = '';
        $lines[] = '# ریشه احتمالی';
        $lines[] = $result['probable_root_cause'] ?? 'نیازمند بررسی کد با توجه به شواهد بالا.';
        $lines[] = '';
        $lines[] = '# فایل‌های مشکوک';
        foreach (($result['suspected_files'] ?? []) ?: ['فایل مشخصی پیشنهاد نشد.'] as $file) $lines[] = '- '.$file;
        $lines[] = '';
        $lines[] = '# پیشنهاد تست';
        $lines[] = $result['test_suggestion'] ?? 'یک تست Feature برای بازتولید سناریوی گزارش‌شده اضافه شود.';
        $lines[] = '';
        $lines[] = '# پرامپت آماده برای Codex';
        $lines[] = '[در فیلد جداگانه codex_prompt ذخیره شده است]';
        return implode("\n", $lines);
    }
}
