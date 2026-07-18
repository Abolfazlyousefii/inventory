<?php

namespace Tests\Feature;

use App\Jobs\Integrations\DeliverAriyaOutboxEventJob;
use App\Models\Integration\IntegrationOutboxEvent;
use Tests\TestCase;

class CatalogOutboxTest extends TestCase
{
    public function test_outbox_model_and_delivery_job_exist(): void
    {
        $this->assertTrue(class_exists(IntegrationOutboxEvent::class));
        $this->assertTrue(class_exists(DeliverAriyaOutboxEventJob::class));
    }
}
