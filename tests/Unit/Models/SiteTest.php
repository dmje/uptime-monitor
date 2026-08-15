<?php

namespace Tests\Unit\Models;

use App\Models\MonitoringLog;
use App\Models\Site;
use App\Models\User;
use App\Models\Vendor;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiteTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function site_model_has_belongs_to_owner_relation()
    {
        $site = Site::factory()->make();

        $this->assertInstanceOf(User::class, $site->owner);
        $this->assertEquals($site->owner_id, $site->owner->id);
    }

    /** @test */
    public function site_model_has_need_to_check_method()
    {
        $site = Site::factory()->make([
            'is_active' => 0,
            'last_check_at' => null,
        ]);
        $this->assertFalse($site->needToCheck());

        $site->is_active = 1;
        $site->last_check_at = null;
        $this->assertTrue($site->needToCheck());

        $site->check_interval = 2;
        $site->last_check_at = '2023-12-11 00:01:16';
        Carbon::setTestNow('2023-12-11 00:02:17');
        $this->assertTrue($site->needToCheck());

        $site->check_interval = 5;
        $site->last_check_at = '2023-12-11 00:00:00';
        Carbon::setTestNow('2023-12-11 00:03:00');
        $this->assertFalse($site->needToCheck());

        $site->check_interval = 5;
        $site->last_check_at = '2023-12-11 00:00:00';
        Carbon::setTestNow('2023-12-11 00:05:00');
        $this->assertTrue($site->needToCheck());

        $site->check_interval = 5;
        $site->last_check_at = '2023-12-11 00:00:00';
        Carbon::setTestNow('2023-12-11 00:04:00');
        $this->assertTrue($site->needToCheck());

        Carbon::setTestNow();
    }

    /** @test */
    public function site_is_only_marked_down_after_the_confirmation_threshold()
    {
        $site = Site::factory()->make(['down_confirmations' => 2]);

        $this->assertNull($site->registerCheckResult($this->failedCheck()));
        $this->assertEquals(Site::STATUS_UNKNOWN, $site->status);
        $this->assertEquals(1, $site->consecutive_failures);

        $this->assertEquals(Site::TRANSITION_DOWN, $site->registerCheckResult($this->failedCheck()));
        $this->assertEquals(Site::STATUS_DOWN, $site->status);
        $this->assertNotNull($site->status_changed_at);

        // Staying down is not a new transition, so it raises no further alert.
        $this->assertNull($site->registerCheckResult($this->failedCheck()));
        $this->assertEquals(Site::STATUS_DOWN, $site->status);
    }

    /** @test */
    public function a_single_failed_check_does_not_mark_the_site_down()
    {
        $site = Site::factory()->make(['down_confirmations' => 2, 'up_confirmations' => 1]);
        $site->registerCheckResult($this->successfulCheck());

        $this->assertNull($site->registerCheckResult($this->failedCheck()));
        $this->assertEquals(1, $site->consecutive_failures);

        $this->assertNull($site->registerCheckResult($this->successfulCheck()));
        $this->assertEquals(Site::STATUS_UP, $site->status);
        $this->assertEquals(0, $site->consecutive_failures);
    }

    /** @test */
    public function site_is_marked_up_again_after_the_confirmation_threshold()
    {
        $site = Site::factory()->make(['down_confirmations' => 1, 'up_confirmations' => 2]);
        $site->registerCheckResult($this->failedCheck());
        $this->assertEquals(Site::STATUS_DOWN, $site->status);

        $this->assertNull($site->registerCheckResult($this->successfulCheck()));
        $this->assertEquals(Site::STATUS_DOWN, $site->status);

        $this->assertEquals(Site::TRANSITION_UP, $site->registerCheckResult($this->successfulCheck()));
        $this->assertEquals(Site::STATUS_UP, $site->status);
    }

    /** @test */
    public function the_first_successful_check_of_an_unknown_site_is_not_a_recovery()
    {
        $site = Site::factory()->make(['up_confirmations' => 1]);

        $this->assertNull($site->registerCheckResult($this->successfulCheck()));
        $this->assertEquals(Site::STATUS_UP, $site->status);
    }

    /** @test */
    public function a_slow_response_counts_as_a_failed_check()
    {
        $site = Site::factory()->make(['down_confirmations' => 1, 'down_threshold' => 10000]);

        $this->assertEquals(Site::TRANSITION_DOWN, $site->registerCheckResult(
            new MonitoringLog(['status_code' => 200, 'response_time' => 10000])
        ));
    }

    /** @test */
    public function site_model_has_needs_down_reminder_method()
    {
        Carbon::setTestNow('2023-12-11 00:00:00');
        $site = Site::factory()->make(['notify_user_interval' => 5, 'down_confirmations' => 1]);

        // An up site is never reminded about.
        $this->assertFalse($site->needsDownReminder());

        $site->registerCheckResult($this->failedCheck());
        $site->alert_count = 1;
        $site->last_notify_user_at = Carbon::now();

        Carbon::setTestNow('2023-12-11 00:04:00');
        $this->assertFalse($site->needsDownReminder());

        // First reminder is due one interval after the down alert.
        Carbon::setTestNow('2023-12-11 00:05:00');
        $this->assertTrue($site->needsDownReminder());

        // The second is due three intervals after the first reminder.
        $site->alert_count = 2;
        $site->last_notify_user_at = Carbon::parse('2023-12-11 00:05:00');
        Carbon::setTestNow('2023-12-11 00:15:00');
        $this->assertFalse($site->needsDownReminder());
        Carbon::setTestNow('2023-12-11 00:20:00');
        $this->assertTrue($site->needsDownReminder());

        // Reminders back off no further than REMINDER_MAX_MINUTES apart.
        $site->alert_count = 20;
        $site->last_notify_user_at = Carbon::parse('2023-12-11 00:20:00');
        Carbon::setTestNow('2023-12-11 06:19:00');
        $this->assertFalse($site->needsDownReminder());
        Carbon::setTestNow('2023-12-11 06:20:00');
        $this->assertTrue($site->needsDownReminder());

        Carbon::setTestNow();
    }

    /** @test */
    public function a_zero_notify_user_interval_switches_reminders_off()
    {
        Carbon::setTestNow('2023-12-11 00:00:00');
        $site = Site::factory()->make(['notify_user_interval' => 0, 'down_confirmations' => 1]);
        $site->registerCheckResult($this->failedCheck());
        $site->alert_count = 1;
        $site->last_notify_user_at = Carbon::now();

        Carbon::setTestNow('2023-12-12 00:00:00');
        $this->assertNull($site->nextReminderDueAt());
        $this->assertFalse($site->needsDownReminder());

        Carbon::setTestNow();
    }

    /** @test */
    public function site_model_has_max_y_axis_attribute()
    {
        $site = Site::factory()->make(['down_threshold' => 10000]);

        $this->assertEquals(12000, $site->y_axis_max);
    }

    /** @test */
    public function site_model_has_y_axis_tick_amount_attribute()
    {
        $site = Site::factory()->make(['down_threshold' => 10000]);

        $this->assertEquals(12, $site->y_axis_tick_amount);
    }

    /** @test */
    public function site_model_has_belongs_to_vendor_relation()
    {
        $vendor = Vendor::factory()->create();
        $site = Site::factory()->create(['vendor_id' => $vendor->id]);

        $this->assertInstanceOf(Vendor::class, $site->vendor);
        $this->assertEquals($site->vendor_id, $site->vendor->id);
    }

    private function failedCheck(): MonitoringLog
    {
        return new MonitoringLog(['status_code' => 500, 'response_time' => 120]);
    }

    private function successfulCheck(): MonitoringLog
    {
        return new MonitoringLog(['status_code' => 200, 'response_time' => 120]);
    }
}
