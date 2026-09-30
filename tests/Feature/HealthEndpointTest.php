<?php

namespace Tests\Feature;

use Tests\TestCase;

class HealthEndpointTest extends TestCase
{
    public function test_laravel_health_endpoint_is_available(): void
    {
        $this->get('/up')->assertOk();
    }
}
