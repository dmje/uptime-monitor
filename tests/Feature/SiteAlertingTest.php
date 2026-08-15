<?php

namespace Tests\Feature;

use App\Jobs\RunCheck;
use App\Models\Site;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SiteAlertingTest extends TestCase
{
    use RefreshDatabase;

    /** @var int Status code the monitored site currently answers with. */
    private $siteStatusCode = 500;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.telegram_notifier.token' => 'test-token']);

        Http::fake(function ($request) {
            if (str_contains($request->url(), 'api.telegram.org')) {
                return Http::response(['ok' => true], 200);
            }

            return Http::response('', $this->siteStatusCode);
        });
    }

    /** @test */
    public function it_alerts_once_when_a_site_goes_down_and_once_when_it_recovers()
    {
        $site = $this->createSite(['down_confirmations' => 2, 'up_confirmations' => 1]);

        // One failed check is not enough to call the site down.
        RunCheck::dispatchSync($site);
        $this->assertCount(0, $this->telegramMessages());
        $this->assertEquals(Site::STATUS_UNKNOWN, $site->fresh()->status);

        // The second failure confirms it, and alerts exactly once.
        RunCheck::dispatchSync($site);
        $this->assertEquals(Site::STATUS_DOWN, $site->fresh()->status);
        $this->assertCount(1, $this->telegramMessages());
        $this->assertStringContainsString('🔴 DOWN: '.$site->name, $this->telegramMessages()->first());

        // Staying down does not send anything more.
        RunCheck::dispatchSync($site);
        RunCheck::dispatchSync($site);
        $this->assertCount(1, $this->telegramMessages());

        // Recovering sends a back up alert, once.
        $this->siteStatusCode = 200;
        RunCheck::dispatchSync($site);
        $this->assertEquals(Site::STATUS_UP, $site->fresh()->status);
        $this->assertCount(2, $this->telegramMessages());
        $this->assertStringContainsString('🟢 BACK UP: '.$site->name, $this->telegramMessages()->last());

        RunCheck::dispatchSync($site);
        $this->assertCount(2, $this->telegramMessages());
    }

    /** @test */
    public function the_down_alert_reports_the_status_of_the_failed_checks()
    {
        $this->siteStatusCode = 502;
        $site = $this->createSite(['down_confirmations' => 2]);

        RunCheck::dispatchSync($site);
        RunCheck::dispatchSync($site);

        $message = $this->telegramMessages()->first();
        $this->assertStringContainsString($site->url, $message);
        $this->assertStringContainsString('Status: HTTP 502 Bad Gateway', $message);
        // The status says it all here, so there is no separate reason line.
        $this->assertStringNotContainsString('Reason:', $message);
        $this->assertStringContainsString('Failed checks: 2 in a row', $message);
        $this->assertStringContainsString('Last 2 checks:', $message);
        $this->assertStringContainsString('Next update in 5 minutes', $message);
        $this->assertStringContainsString(route('sites.show', [$site->id]), $message);
    }

    /** @test */
    public function the_down_alert_reports_the_error_when_the_site_never_answered()
    {
        $site = $this->createSite(['down_confirmations' => 1]);
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'api.telegram.org')) {
                return Http::response(['ok' => true], 200);
            }

            throw new ConnectionException('cURL error 28: Operation timed out after 20000 milliseconds');
        });

        RunCheck::dispatchSync($site);

        $message = $this->telegramMessages()->first();
        $this->assertStringContainsString('Status: No response', $message);
        $this->assertStringContainsString('Reason: cURL error 28: Operation timed out', $message);
    }

    /** @test */
    public function the_recovery_alert_reports_how_long_the_site_was_down()
    {
        $site = $this->createSite(['down_confirmations' => 1, 'up_confirmations' => 1]);

        Carbon::setTestNow('2023-12-11 00:00:00');
        RunCheck::dispatchSync($site);

        Carbon::setTestNow('2023-12-11 00:12:00');
        $this->siteStatusCode = 200;
        RunCheck::dispatchSync($site);

        $message = $this->telegramMessages()->last();
        $this->assertStringContainsString('Status: HTTP 200 OK', $message);
        $this->assertStringContainsString('Down for: 12 minutes', $message);
        $this->assertStringContainsString('Recovered at: 2023-12-11 00:12:00', $message);

        Carbon::setTestNow();
    }

    /** @test */
    public function it_reminds_about_a_site_that_stays_down_on_a_backing_off_schedule()
    {
        $site = $this->createSite(['down_confirmations' => 1, 'notify_user_interval' => 5]);

        Carbon::setTestNow('2023-12-11 00:00:00');
        RunCheck::dispatchSync($site);
        $this->assertCount(1, $this->telegramMessages());

        // Too soon for a reminder.
        Carbon::setTestNow('2023-12-11 00:04:00');
        $this->artisan('notify-user');
        $this->assertCount(1, $this->telegramMessages());

        // First reminder, one interval after the down alert.
        Carbon::setTestNow('2023-12-11 00:05:00');
        $this->artisan('notify-user');
        $this->assertCount(2, $this->telegramMessages());
        $this->assertStringContainsString('🔴 STILL DOWN: '.$site->name, $this->telegramMessages()->last());
        $this->assertStringContainsString('Status: HTTP 500 Internal Server Error', $this->telegramMessages()->last());

        // The next one is three intervals later, not one.
        Carbon::setTestNow('2023-12-11 00:10:00');
        $this->artisan('notify-user');
        $this->assertCount(2, $this->telegramMessages());

        Carbon::setTestNow('2023-12-11 00:20:00');
        $this->artisan('notify-user');
        $this->assertCount(3, $this->telegramMessages());

        Carbon::setTestNow();
    }

    /** @test */
    public function it_sends_no_reminders_when_the_notify_user_interval_is_zero()
    {
        $site = $this->createSite(['down_confirmations' => 1, 'notify_user_interval' => 0]);

        Carbon::setTestNow('2023-12-11 00:00:00');
        RunCheck::dispatchSync($site);
        $this->assertCount(1, $this->telegramMessages());
        $this->assertStringContainsString('No reminders while down', $this->telegramMessages()->first());

        Carbon::setTestNow('2023-12-12 00:00:00');
        $this->artisan('notify-user');
        $this->assertCount(1, $this->telegramMessages());

        Carbon::setTestNow();
    }

    /** @test */
    public function it_sends_nothing_for_a_site_owner_without_a_telegram_chat_id()
    {
        $owner = User::factory()->create(['telegram_chat_id' => null]);
        $site = $this->createSite(['down_confirmations' => 1, 'owner_id' => $owner->id]);

        RunCheck::dispatchSync($site);

        $this->assertCount(0, $this->telegramMessages());
        // The attempt is still counted, so reminders back off instead of
        // retrying on every scheduler run.
        $this->assertEquals(1, $site->fresh()->alert_count);
    }

    private function createSite(array $attributes = []): Site
    {
        $owner = User::factory()->create(['telegram_chat_id' => '123456']);

        return Site::factory()->create(array_merge([
            'owner_id' => $owner->id,
            'url' => 'https://monitored.example.com',
            'notify_user_interval' => 5,
        ], $attributes));
    }

    /**
     * The text of every Telegram message sent so far, oldest first.
     */
    private function telegramMessages(): Collection
    {
        return collect(Http::recorded())
            ->filter(fn ($record) => str_contains($record[0]->url(), 'api.telegram.org'))
            ->map(fn ($record) => $record[0]->data()['text'])
            ->values();
    }
}
