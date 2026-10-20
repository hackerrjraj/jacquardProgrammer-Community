<?php

namespace Tests\Feature;

use Tests\TestCase;

class HealthTest extends TestCase
{
    public function test_health_endpoint_reports_ok(): void
    {
        $response = $this->getJson('/api/health');

        $response->assertOk()
            ->assertJson([
                'status' => 'ok',
                'app' => 'JPPHAF',
                'database' => 'connected',
            ])
            ->assertJsonStructure(['status', 'app', 'database', 'time']);

        $this->assertStringEndsWith('+06:00', $response->json('time'));
    }

    public function test_cors_allows_only_the_frontend(): void
    {
        $this->getJson('/api/health', ['Origin' => 'http://localhost:3000'])
            ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:3000')
            ->assertHeader('Access-Control-Allow-Credentials', 'true');

        // Other sites never get their own origin echoed back, so browsers block them.
        $this->getJson('/api/health', ['Origin' => 'http://evil.example'])
            ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:3000');
    }
}
