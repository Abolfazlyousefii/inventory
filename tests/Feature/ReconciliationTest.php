<?php

namespace Tests\Feature;

use App\Console\Commands\ReconcileAriyaSiteCatalog;
use Tests\TestCase;

class ReconciliationTest extends TestCase
{
    public function test_reconciliation_command_class_exists(): void
    {
        $this->assertTrue(class_exists(ReconcileAriyaSiteCatalog::class));
    }
}
