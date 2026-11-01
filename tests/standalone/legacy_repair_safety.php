<?php

namespace Illuminate\Console {
    class Command
    {
        public function option($name) { return null; }
        public function error($message): void {}
        public function line($message): void {}
    }
}
namespace Symfony\Component\Console\Command {
    class Command { public const FAILURE = 1; public const SUCCESS = 0; }
}
namespace App\Services\Inventory { class InventoryCorruptionAuditService {} }
namespace {
    require_once __DIR__.'/../../app/Console/Commands/InventoryRepairCorruptionCommand.php';

    class RepairSafetyProbe extends \App\Console\Commands\InventoryRepairCorruptionCommand
    {
        public array $flags = [];
        public function option($name) { return $this->flags[$name] ?? null; }
    }

    $command = new RepairSafetyProbe();
    $audit = new \App\Services\Inventory\InventoryCorruptionAuditService();
    foreach ([
        ['apply' => true, 'backup' => true, 'force' => true, 'all' => true],
        ['apply' => true, 'product' => 7],
        ['apply' => true, 'dry-run' => true],
    ] as $flags) {
        $command->flags = $flags;
        if ($command->handle($audit) !== 1) {
            throw new \RuntimeException('A legacy apply option bypassed the safety guard.');
        }
    }
    echo "3 legacy-repair apply guard assertions passed.\n";
}
