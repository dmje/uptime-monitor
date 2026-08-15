<?php

namespace App\Console\Commands;

use App\Models\Site;
use App\Services\SiteAlerter;
use Illuminate\Console\Command;

class NotifyUser extends Command
{
    protected $signature = 'notify-user';

    protected $description = 'Send reminders for sites that are still down';

    /**
     * Down and recovery alerts are sent by the check itself (see RunCheck), so
     * this command only chases sites that stay down, on a backing off schedule.
     */
    public function handle(SiteAlerter $siteAlerter): void
    {
        if (!$siteAlerter->isConfigured()) {
            return;
        }

        $sites = Site::query()
            ->where('is_active', 1)
            ->where('status', Site::STATUS_DOWN)
            ->get();

        foreach ($sites as $site) {
            if (!$site->needsDownReminder()) {
                continue;
            }

            $monitoringLog = $site->monitoringLogs()->latest('id')->first();

            $siteAlerter->alertStillDown($site, $monitoringLog);
        }

        $this->info('Done!');
    }
}
