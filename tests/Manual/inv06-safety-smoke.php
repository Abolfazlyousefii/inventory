<?php
// Lightweight executable smoke checks for command safety and no-Laravel helpers.
namespace Illuminate\Console {
    class Command {
        const SUCCESS = 0;
        const FAILURE = 1;
        const INVALID = 2;
        public array $fakeOptions = [];
        public array $lines = [];
        public function option($key) { return $this->fakeOptions[$key] ?? null; }
        public function error($message): void { $this->lines[] = 'ERROR '.$message; }
        public function warn($message): void { $this->lines[] = 'WARN '.$message; }
        public function line($message): void { $this->lines[] = $message; }
    }
}
namespace App\Services { class ReservationClassificationService {} }
namespace {
    require __DIR__.'/../app/Console/Commands/ReviewHistoricalReservations.php';
    $class = new \ReflectionClass(\App\Console\Commands\ReviewHistoricalReservations::class);
    $command = $class->newInstance();
    $prop = $class->getProperty('signature');
    $sig = $prop->getValue($command);
    if (str_contains($sig, '--apply') || str_contains($sig, '--confirm') || str_contains($sig, '--all-historical')) throw new \RuntimeException('Unexpected write mode in command');
    echo "PASS no apply/archive options in command\n";
    $policy = $class->getMethod('csv');
    $csv = $policy->invoke($command, [[
        'reservation_id'=>7,'reservation_user_id'=>null,'preinvoice_order_id'=>12,'quantity'=>60,'classification'=>'historical_ambiguous','classification_reason'=>'manual',
        'product_id'=>3,'variant_id'=>8,'order'=>['id'=>12,'status'=>'cancelled','matching_line_quantity'=>60],
        'review'=>['status'=>'OPEN_MANUAL_REVIEW'], 'inventory_snapshot'=>[]
    ]], [101]);
    if (!str_contains($csv,'OPEN_MANUAL_REVIEW') || !str_contains($csv,'NOT_FOUND')) throw new \RuntimeException('CSV must preserve pending decisions');
    $csvRows = array_map('str_getcsv', array_filter(explode("\n", trim($csv))));
    if (count($csvRows[0]) !== count($csvRows[1]) || count($csvRows[0]) !== count($csvRows[2])) {
        throw new \RuntimeException('CSV column count differs between header, found, and missing rows');
    }
    echo "PASS CSV columns align and cases remain OPEN (including missing IDs)\n";
    foreach (['-5','1,0','1,abc','1;2','9999999999999999999999999999'] as $invalid) {
        $command = $class->newInstance();
        $command->fakeOptions=['ids'=>$invalid, 'summary'=>true];
        $result=$command->handle(new \App\Services\ReservationClassificationService());
        if ($result!==2) throw new \RuntimeException('invalid ID accepted: '.$invalid);
    }
    echo "PASS rejects malformed and overflowed reservation IDs before any DB calls\n";
    $command=$class->newInstance();
    $command->fakeOptions=['ids'=>'1', 'summary'=>false, 'output'=>'../../unsafe'];
    $result=$command->handle(new \App\Services\ReservationClassificationService());
    if ($result!==2) throw new \RuntimeException('Unsafe path accepted');
    echo "PASS rejects unsafe output path before any DB calls\n";
}
