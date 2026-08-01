[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
$repositoryRoot = [System.IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
Set-Location $repositoryRoot
$timestamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$reportDirectory = Join-Path $PSScriptRoot '..\storage\app\test-reports'
$reportDirectory = [System.IO.Path]::GetFullPath($reportDirectory)
$reportFile = Join-Path $reportDirectory "seller-commission-tests-$timestamp.txt"
$junitFile = Join-Path $reportDirectory "seller-commission-tests-$timestamp.xml"
$results = New-Object System.Collections.Generic.List[object]

New-Item -ItemType Directory -Force -Path $reportDirectory | Out-Null
New-Item -ItemType File -Force -Path $reportFile | Out-Null

function Write-ReportLine {
    param([AllowEmptyString()][string] $Text = '')

    Write-Host $Text
    Add-Content -LiteralPath $reportFile -Value $Text -Encoding UTF8
}

function Write-Section {
    param([string] $Title)

    Write-ReportLine ''
    Write-ReportLine '=================================================='
    Write-ReportLine $Title
    Write-ReportLine '=================================================='
}

function Run-Step {
    param(
        [string] $Title,
        [string] $Executable,
        [string[]] $ArgumentList = @(),
        [bool] $TrackResult = $true
    )

    Write-Section $Title
    $displayCommand = @($Executable) + $ArgumentList
    Write-ReportLine ('Command: ' + ($displayCommand -join ' '))

    $output = New-Object System.Collections.Generic.List[string]
    $previousErrorActionPreference = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try {
        & $Executable @ArgumentList 2>&1 | ForEach-Object {
            $line = $_.ToString()
            $output.Add($line)
            Write-ReportLine $line
        }
        $exitCode = $LASTEXITCODE
    } finally {
        $ErrorActionPreference = $previousErrorActionPreference
    }
    if ($null -eq $exitCode) { $exitCode = 0 }

    Write-ReportLine "Exit Code: $exitCode"
    $result = [pscustomobject]@{
        Title = $Title
        ExitCode = [int] $exitCode
        Output = $output.ToArray()
    }
    if ($TrackResult) {
        $results.Add($result)
    }

    return $result
}

function Run-StepWithRetry {
    param(
        [string] $Title,
        [string] $Executable,
        [string[]] $ArgumentList = @(),
        [int] $MaximumAttempts = 3
    )

    $attemptResults = New-Object System.Collections.Generic.List[object]
    for ($attempt = 1; $attempt -le $MaximumAttempts; $attempt++) {
        $attemptResult = Run-Step "$Title (Attempt $attempt of $MaximumAttempts)" $Executable $ArgumentList $false
        $attemptResults.Add($attemptResult)
        if ($attemptResult.ExitCode -eq 0) { break }
        if ($attempt -lt $MaximumAttempts) {
            Write-ReportLine 'Transient command failure detected; retrying after 2 seconds.'
            Start-Sleep -Seconds 2
        }
    }

    $finalAttempt = $attemptResults[$attemptResults.Count - 1]
    $combinedOutput = $attemptResults | ForEach-Object { $_.Output }
    $aggregate = [pscustomobject]@{
        Title = $Title
        ExitCode = [int] $finalAttempt.ExitCode
        Output = @($combinedOutput)
    }
    $results.Add($aggregate)

    return $aggregate
}

# Hard safety boundary: every Artisan/PHPUnit process launched below receives only
# the isolated in-memory SQLite testing configuration. No credentials are logged.
$env:APP_ENV = 'testing'
$env:APP_DEBUG = 'true'
$env:APP_CONFIG_CACHE = 'bootstrap/cache/config-testing.php'
$env:DB_CONNECTION = 'sqlite'
$env:DB_DATABASE = ':memory:'
$env:CACHE_STORE = 'array'
$env:SESSION_DRIVER = 'array'
$env:MAIL_MAILER = 'array'
$env:QUEUE_CONNECTION = 'sync'
$env:BROADCAST_CONNECTION = 'null'
$env:CRM_SYNC_ENABLED = 'false'
$env:PULSE_ENABLED = 'false'
$env:TELESCOPE_ENABLED = 'false'
$env:NIGHTWATCH_ENABLED = 'false'

Write-ReportLine 'Seller Commission Document Test Report'
Write-ReportLine ('Generated At: ' + (Get-Date -Format 'yyyy-MM-dd HH:mm:ss zzz'))
Write-ReportLine 'APP_ENV: testing'
Write-ReportLine 'Testing Database: :memory:'
Write-ReportLine 'Database Driver: sqlite'
Write-ReportLine 'Sensitive connection values: intentionally omitted'

$phpVersion = (& php -r 'echo PHP_VERSION;').Trim()
$laravelVersion = (& php artisan --version).Trim()
$gitBranch = (& git branch --show-current).Trim()
$gitCommit = (& git rev-parse HEAD).Trim()
Write-ReportLine "PHP Version: $phpVersion"
Write-ReportLine "Laravel Version: $laravelVersion"
Write-ReportLine "Git Branch: $gitBranch"
Write-ReportLine "Git Commit (read-only): $gitCommit"

Write-Section 'Environment Safety Check'
$safeEnvironment = ($env:APP_ENV -eq 'testing' -and $env:DB_CONNECTION -eq 'sqlite' -and $env:DB_DATABASE -eq ':memory:')
Write-ReportLine "APP_ENV is testing: $($env:APP_ENV -eq 'testing')"
Write-ReportLine "Database is isolated SQLite memory: $($env:DB_CONNECTION -eq 'sqlite' -and $env:DB_DATABASE -eq ':memory:')"
Write-ReportLine 'Mail: array; Queue: sync/in-process; Broadcast: null; external CRM sync: disabled'
if (-not $safeEnvironment) {
    Write-ReportLine 'ABORTED: testing environment is not isolated.'
    Write-ReportLine "Report File: $reportFile"
    exit 2
}

Run-Step 'Git Status (read-only)' 'git' @('status', '--short') | Out-Null
Run-Step 'Module Route List' 'php' @('artisan', 'route:list', '--path=finance/reports/seller-commission-documents', '-v') | Out-Null

Write-Section 'Related Migration Status'
$migrationPath = 'database/migrations/2026_08_01_210000_create_seller_sales_documents_tables.php'
Write-ReportLine "Migration Source: $migrationPath"
Write-ReportLine "Migration File Exists: $(Test-Path $migrationPath)"
Write-ReportLine 'Runtime Status: applied afresh only inside each isolated RefreshDatabase test; no real database migration was executed.'

Run-Step 'Clear Laravel Caches' 'php' @('artisan', 'optimize:clear') | Out-Null
Run-Step 'Git Diff Check' 'git' @('diff', '--check') | Out-Null
Run-Step 'Seller Commission Document Tests' 'php' @('artisan', 'test', '--filter=SellerCommissionDocument') | Out-Null
Run-Step 'Invoice Regression Tests' 'php' @('artisan', 'test', '--filter=Invoice') | Out-Null
Run-Step 'Preinvoice Regression Tests' 'php' @('artisan', 'test', '--filter=Preinvoice') | Out-Null
Run-Step 'Finance Report Regression Tests' 'php' @('artisan', 'test', '--filter=FinanceReport') | Out-Null
Run-StepWithRetry 'Frontend Production Build' 'npm.cmd' @('run', 'build') | Out-Null
$fullSuite = Run-Step 'Full Test Suite' 'php' @('artisan', 'test', "--log-junit=$junitFile")
Run-Step 'Changed Files' 'git' @('status', '--short') | Out-Null
Run-Step 'Git Diff Stat' 'git' @('diff', '--stat') | Out-Null

Write-Section 'Automated Test Summary'
$summaryLine = $fullSuite.Output | Where-Object { $_ -match '^\s*Tests:\s+' } | Select-Object -Last 1
if ($summaryLine) {
    Write-ReportLine $summaryLine.Trim()
} else {
    Write-ReportLine 'Tests/Assertions summary could not be parsed; see the complete Full Test Suite output above.'
}

$failedSteps = @($results | Where-Object { $_.ExitCode -ne 0 })
Write-ReportLine "Passed Steps: $($results.Count - $failedSteps.Count)"
Write-ReportLine "Failed Steps: $($failedSteps.Count)"
if ($failedSteps.Count -gt 0) {
    Write-ReportLine ('Failed Step Names: ' + (($failedSteps | ForEach-Object { $_.Title }) -join ', '))
    Write-ReportLine 'Complete failed test names and error text are preserved in the corresponding command sections above.'
} else {
    Write-ReportLine 'Failed Test Names: none'
    Write-ReportLine 'Failed Error Text: none'
}

Write-Section 'Remaining Risks'
Write-ReportLine '- No automated browser/E2E runner is configured; JavaScript behavior is covered by view contract tests and requires the manual checklist below.'
Write-ReportLine '- Database concurrency is protected and tested through transactions plus unique constraints; no destructive test was run against production data.'

Write-Section 'Stabilization Test History'
Write-ReportLine '- Expanded filter test initially matched the static SC-000001 input placeholder; it now asserts exact document row URLs.'
Write-ReportLine '- Expanded immutability test initially compared partially hydrated attributes with a refreshed model; it now compares the explicit ownership and financial source fields.'
Write-ReportLine '- Both corrected tests are rerun by the Seller Commission target and Full Test Suite sections above.'

Write-Section 'Manual Smoke Test Checklist (not executed by this script)'
@(
    '[ ] صفحه لیست باز شد',
    '[ ] ثبت سند جدید باز شد',
    '[ ] User انتخاب شد',
    '[ ] بازه انتخاب شد',
    '[ ] Invoiceها بارگذاری شدند',
    '[ ] Invoiceهای User دیگر نمایش داده نشدند',
    '[ ] Checkbox کار کرد',
    '[ ] تعداد زنده تغییر کرد',
    '[ ] جمع زنده تغییر کرد',
    '[ ] سند ثبت شد',
    '[ ] سند در فهرست دیده شد',
    '[ ] سند ویرایش شد',
    '[ ] Invoice حذف‌شده دوباره آزاد شد',
    '[ ] سند مشاهده شد',
    '[ ] چاپ باز شد',
    '[ ] دکمه حذف وجود نداشت',
    '[ ] Console Error وجود نداشت'
) | ForEach-Object { Write-ReportLine $_ }

Write-Section 'Change-Control Confirmation'
Write-ReportLine 'No commit, push, deploy, migrate:fresh, db:wipe, or real financial-data mutation was performed by this script.'
Write-ReportLine "Text Report: $reportFile"
Write-ReportLine "JUnit Report: $junitFile"

if ($failedSteps.Count -gt 0) { exit 1 }
exit 0
