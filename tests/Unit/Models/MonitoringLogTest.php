<?php

namespace Tests\Unit\Models;

use App\Models\MonitoringLog;
use App\Models\Site;
use Tests\TestCase;

class MonitoringLogTest extends TestCase
{
    /** @test */
    public function monitoring_log_knows_when_a_check_failed()
    {
        $site = new Site(['down_threshold' => 10000]);

        $this->assertFalse((new MonitoringLog(['status_code' => 200, 'response_time' => 300]))->isFailureFor($site));
        $this->assertFalse((new MonitoringLog(['status_code' => 301, 'response_time' => 300]))->isFailureFor($site));
        $this->assertTrue((new MonitoringLog(['status_code' => 404, 'response_time' => 300]))->isFailureFor($site));
        $this->assertTrue((new MonitoringLog(['status_code' => 503, 'response_time' => 300]))->isFailureFor($site));
        $this->assertTrue((new MonitoringLog(['status_code' => null, 'response_time' => 300]))->isFailureFor($site));

        // Slower than the down threshold counts as down.
        $this->assertFalse((new MonitoringLog(['status_code' => 200, 'response_time' => 9999]))->isFailureFor($site));
        $this->assertTrue((new MonitoringLog(['status_code' => 200, 'response_time' => 10000]))->isFailureFor($site));

        // A response message means the request threw before we got a response.
        $this->assertTrue((new MonitoringLog([
            'status_code' => 500,
            'response_time' => 300,
            'response_message' => 'cURL error 6: Could not resolve host',
        ]))->isFailureFor($site));
    }

    /** @test */
    public function monitoring_log_has_a_status_label()
    {
        $this->assertEquals('HTTP 200 OK', (new MonitoringLog(['status_code' => 200]))->statusLabel());
        $this->assertEquals('HTTP 502 Bad Gateway', (new MonitoringLog(['status_code' => 502]))->statusLabel());
        $this->assertEquals('Unknown', (new MonitoringLog(['status_code' => null]))->statusLabel());
        $this->assertEquals('No response', (new MonitoringLog([
            'status_code' => 500,
            'response_message' => 'cURL error 6: Could not resolve host',
        ]))->statusLabel());
    }

    /** @test */
    public function monitoring_log_explains_why_a_check_failed()
    {
        $site = new Site(['down_threshold' => 10000]);

        $this->assertNull((new MonitoringLog(['status_code' => 200, 'response_time' => 300]))->failureReasonFor($site));

        $this->assertEquals(
            'HTTP 502 Bad Gateway',
            (new MonitoringLog(['status_code' => 502, 'response_time' => 300]))->failureReasonFor($site)
        );

        $this->assertEquals(
            'Too slow: 12,000 ms (down threshold 10,000 ms)',
            (new MonitoringLog(['status_code' => 200, 'response_time' => 12000]))->failureReasonFor($site)
        );

        $this->assertEquals(
            'cURL error 6: Could not resolve host',
            (new MonitoringLog([
                'status_code' => 500,
                'response_time' => 300,
                'response_message' => "cURL error 6: Could not resolve host\nsecond line",
            ]))->failureReasonFor($site)
        );
    }
}
