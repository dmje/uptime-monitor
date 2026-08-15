<?php

namespace App\Models;

use App\Models\MonitoringLog;
use Carbon\Carbon;
use Carbon\CarbonInterval;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Site extends Model
{
    use HasFactory;

    public const STATUS_UP = 'up';
    public const STATUS_DOWN = 'down';
    public const STATUS_UNKNOWN = 'unknown';

    public const TRANSITION_DOWN = 'down';
    public const TRANSITION_UP = 'up';

    /**
     * Reminder backoff, as multipliers of the notify_user_interval. With the
     * default 5 minute interval that gives reminders after 5m, 15m, 1h, then
     * every 6 hours (see REMINDER_MAX_MINUTES) for as long as the site is down.
     */
    public const REMINDER_BACKOFF_STEPS = [1, 3, 12, 72];

    public const REMINDER_MAX_MINUTES = 360;

    /**
     * When status_changed_at was last overwritten, kept in memory so a recovery
     * alert can still report how long the outage lasted.
     */
    public ?Carbon $previousStatusChangedAt = null;

    protected $fillable = [
        'name', 'url', 'vendor_id', 'is_active', 'owner_id', 'check_interval', 'priority_code',
        'warning_threshold', 'down_threshold', 'notify_user_interval', 'last_check_at',
        'down_confirmations', 'up_confirmations', 'last_notify_user_at', 'status',
        'status_changed_at', 'consecutive_failures', 'consecutive_successes', 'alert_count',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'notify_user' => 'boolean',
        'last_check_at' => 'datetime',
        'last_notify_user_at' => 'datetime',
        'status_changed_at' => 'datetime',
    ];

    public function latestLogs()
    {
        return $this->hasMany(MonitoringLog::class)->latest();
    }

    public function monitoringLogs()
    {
        return $this->hasMany(MonitoringLog::class);
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id')->withDefault(['name' => 'n/a']);
    }

    public function vendor()
    {
        return $this->belongsTo(Vendor::class)->withDefault(['name' => 'n/a']);
    }

    public function needToCheck(): bool
    {
        if (!$this->is_active) {
            return false;
        }

        if (!$this->last_check_at) {
            return true;
        }

        if ($this->last_check_at->diffInMinutes() < ($this->check_interval - 1)) {
            return false;
        }

        return true;
    }

    public function isDown(): bool
    {
        return $this->status === self::STATUS_DOWN;
    }

    /**
     * Apply a check result to the site status, without saving.
     *
     * A site is only declared down after down_confirmations consecutive failed
     * checks, and back up after up_confirmations consecutive successful ones,
     * so a single blip does not raise an alert.
     *
     * Returns the status transition this check caused ('down', 'up'), or null
     * when the status did not change in a way worth alerting on.
     */
    public function registerCheckResult(MonitoringLog $monitoringLog): ?string
    {
        if ($monitoringLog->isFailureFor($this)) {
            $this->consecutive_failures++;
            $this->consecutive_successes = 0;
        } else {
            $this->consecutive_successes++;
            $this->consecutive_failures = 0;
        }

        if (!$this->isDown() && $this->consecutive_failures >= $this->down_confirmations) {
            $this->markStatus(self::STATUS_DOWN);

            return self::TRANSITION_DOWN;
        }

        if ($this->status !== self::STATUS_UP && $this->consecutive_successes >= $this->up_confirmations) {
            $wasDown = $this->isDown();
            $this->markStatus(self::STATUS_UP);

            // Coming up from 'unknown' is the first ever result for this site,
            // or the first after a restart, so there is nothing to announce.
            return $wasDown ? self::TRANSITION_UP : null;
        }

        return null;
    }

    /**
     * How long the site has been in its current status.
     */
    public function statusDurationForHumans(): ?string
    {
        if (is_null($this->status_changed_at)) {
            return null;
        }

        return $this->status_changed_at->diffForHumans(Carbon::now(), true, false, 2);
    }

    /**
     * When the next "still down" reminder is due, or null when reminders are
     * switched off (notify_user_interval of 0) or the site is not down.
     */
    public function nextReminderDueAt(): ?Carbon
    {
        if (!$this->is_active || !$this->isDown()) {
            return null;
        }

        $interval = (int) $this->notify_user_interval;
        if ($interval < 1) {
            return null;
        }

        $lastStep = count(self::REMINDER_BACKOFF_STEPS) - 1;
        $step = self::REMINDER_BACKOFF_STEPS[max(0, min((int) $this->alert_count - 1, $lastStep))];
        $delay = min($interval * $step, self::REMINDER_MAX_MINUTES);

        $sentAt = $this->last_notify_user_at ?: $this->status_changed_at;
        if (is_null($sentAt)) {
            return null;
        }

        return $sentAt->copy()->addMinutes($delay);
    }

    public function needsDownReminder(): bool
    {
        $dueAt = $this->nextReminderDueAt();

        return !is_null($dueAt) && !$dueAt->isFuture();
    }

    /**
     * The delay before the reminder that follows the one being sent now, used
     * to tell the user when to expect the next message.
     */
    public function nextReminderDelayForHumans(): ?string
    {
        $interval = (int) $this->notify_user_interval;
        if ($interval < 1) {
            return null;
        }

        $lastStep = count(self::REMINDER_BACKOFF_STEPS) - 1;
        $step = self::REMINDER_BACKOFF_STEPS[max(0, min((int) $this->alert_count, $lastStep))];
        $delay = min($interval * $step, self::REMINDER_MAX_MINUTES);

        return CarbonInterval::minutes($delay)->cascade()->forHumans();
    }

    public function getYAxisMaxAttribute()
    {
        return $this->down_threshold + 2000;
    }

    public function getYAxisTickAmountAttribute()
    {
        return $this->y_axis_max / 1000;
    }

    private function markStatus(string $status): void
    {
        $this->previousStatusChangedAt = $this->status_changed_at;
        $this->status = $status;
        $this->status_changed_at = Carbon::now();
        $this->alert_count = 0;
        $this->last_notify_user_at = null;
    }
}
