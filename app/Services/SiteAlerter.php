<?php

namespace App\Services;

use App\Models\MonitoringLog;
use App\Models\Site;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class SiteAlerter
{
    public function __construct(private TelegramNotifier $notifier)
    {
    }

    public function isConfigured(): bool
    {
        return $this->notifier->isConfigured();
    }

    /**
     * Send the alert for a status transition returned by
     * Site::registerCheckResult(), if there is one to send.
     */
    public function alertTransition(Site $site, ?string $transition, MonitoringLog $monitoringLog): void
    {
        if ($transition === Site::TRANSITION_DOWN) {
            $this->alertDown($site, $monitoringLog);
        }

        if ($transition === Site::TRANSITION_UP) {
            $this->alertRecovered($site, $monitoringLog);
        }
    }

    public function alertDown(Site $site, MonitoringLog $monitoringLog): void
    {
        $text = '🔴 DOWN: '.$site->name;
        $text .= "\n".$site->url;
        $text .= "\n\nStatus: ".$monitoringLog->statusLabel();

        $reason = $monitoringLog->failureReasonFor($site);
        if ($reason && $reason !== $monitoringLog->statusLabel()) {
            $text .= "\nReason: ".$reason;
        }

        $text .= "\nResponse time: ".number_format($monitoringLog->response_time).' ms';
        $text .= "\nFailed checks: ".$site->consecutive_failures.' in a row';
        $text .= "\nDown since: ".$this->formatTime($site->status_changed_at);
        $text .= "\n\n".$this->recentChecks($site);
        $text .= "\n".$this->nextReminderLine($site);
        $text .= "\n\nDetails: ".route('sites.show', [$site->id]);

        $this->send($site, $text);
    }

    public function alertStillDown(Site $site, ?MonitoringLog $monitoringLog): void
    {
        $text = '🔴 STILL DOWN: '.$site->name;
        $text .= ' ('.$site->statusDurationForHumans().')';
        $text .= "\n".$site->url;

        if ($monitoringLog) {
            $text .= "\n\nStatus: ".$monitoringLog->statusLabel();

            if ($reason = $monitoringLog->failureReasonFor($site)) {
                $text .= "\nReason: ".$reason;
            }
        }

        $text .= "\nDown since: ".$this->formatTime($site->status_changed_at);
        $text .= "\n\n".$this->recentChecks($site);
        $text .= "\n".$this->nextReminderLine($site);
        $text .= "\n\nDetails: ".route('sites.show', [$site->id]);

        $this->send($site, $text);
    }

    public function alertRecovered(Site $site, MonitoringLog $monitoringLog): void
    {
        $downtime = $this->downtimeForHumans($site);

        $text = '🟢 BACK UP: '.$site->name;
        $text .= "\n".$site->url;
        $text .= "\n\nStatus: ".$monitoringLog->statusLabel();
        $text .= "\nResponse time: ".number_format($monitoringLog->response_time).' ms';

        if ($downtime) {
            $text .= "\nDown for: ".$downtime;
        }

        $text .= "\nRecovered at: ".$this->formatTime($site->status_changed_at);
        $text .= "\n\n".$this->recentChecks($site);
        $text .= "\n\nDetails: ".route('sites.show', [$site->id]);

        $this->send($site, $text);
    }

    /**
     * The last few checks, so the message shows what the site was doing either
     * side of the transition rather than just the single check that tipped it.
     */
    private function recentChecks(Site $site, int $take = 5): string
    {
        $monitoringLogs = $site->monitoringLogs()
            ->latest('id')
            ->take($take)
            ->get(['response_time', 'status_code', 'response_message', 'created_at']);

        if ($monitoringLogs->isEmpty()) {
            return '';
        }

        return "Last {$monitoringLogs->count()} checks:\n".$this->formatChecks($monitoringLogs);
    }

    private function formatChecks(Collection $monitoringLogs): string
    {
        return $monitoringLogs
            ->map(function (MonitoringLog $monitoringLog) {
                return $monitoringLog->created_at->format('H:i:s')
                    .'  '.$monitoringLog->statusLabel()
                    .'  '.number_format($monitoringLog->response_time).' ms';
            })
            ->implode("\n");
    }

    private function nextReminderLine(Site $site): string
    {
        $nextReminder = $site->nextReminderDelayForHumans();

        if (is_null($nextReminder)) {
            return "\nNo reminders while down; you will get a message when it is back up.";
        }

        return "\nNext update in ".$nextReminder.' unless it recovers first.';
    }

    private function downtimeForHumans(Site $site): ?string
    {
        // status_changed_at has already moved to the recovery time, so the
        // outage length is measured from when the site was marked down.
        $downSince = $site->previousStatusChangedAt;

        if (is_null($downSince)) {
            return null;
        }

        return $downSince->diffForHumans($site->status_changed_at ?: Carbon::now(), true, false, 2);
    }

    private function formatTime(?Carbon $time): string
    {
        return $time ? $time->format('Y-m-d H:i:s') : 'n/a';
    }

    private function send(Site $site, string $text): void
    {
        $chatId = $site->owner->telegram_chat_id;

        if (is_null($chatId)) {
            Log::channel('daily')->info('Missing telegram_chat_id for site owner', $site->toArray());
        } else {
            $this->notifier->send($chatId, $text);
        }

        // Count the attempt either way, so a misconfigured site backs off
        // instead of retrying (and logging) on every scheduler run.
        $site->alert_count = (int) $site->alert_count + 1;
        $site->last_notify_user_at = Carbon::now();
        $site->save();
    }
}
