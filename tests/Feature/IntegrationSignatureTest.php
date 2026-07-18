<?php

namespace Tests\Feature;

use Tests\TestCase;

class IntegrationSignatureTest extends TestCase
{
    public function test_signature_contract_is_documented(): void
    {
        $this->assertFileExists(base_path('docs/integrations/ariya-site-v1.md'));
        $this->assertStringContainsString('hash_hmac', file_get_contents(base_path('docs/integrations/ariya-site-v1.md')));
    }
}
