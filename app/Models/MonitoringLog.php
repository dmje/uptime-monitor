<?php

namespace App\Models;

use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class MonitoringLog extends Model
{
    use HasFactory;

    protected $fillable = ['site_id', 'url', 'response_time', 'status_code', 'response_message'];

    public function site()
    {
        return $this->belongsTo(Site::class)->withDefault(['name' => 'n/a']);
    }

    /**
     * A check counts as a failure when the request could not be completed,
     * the site answered with an error status, or it answered so slowly that
     * it is past the site down threshold.
     */
    public function isFailureFor(Site $site): bool
    {
        if ($this->isConnectionError()) {
            return true;
        }

        if (is_null($this->status_code) || $this->status_code >= 400) {
            return true;
        }

        return $this->response_time >= $site->down_threshold;
    }

    /**
     * A response message is only recorded when the request threw, so its
     * presence means we never got an HTTP response back from the site.
     */
    public function isConnectionError(): bool
    {
        return !is_null($this->response_message);
    }

    /**
     * Short label for the check result, e.g. "HTTP 502 Bad Gateway".
     */
    public function statusLabel(): string
    {
        if ($this->isConnectionError()) {
            return 'No response';
        }

        if (is_null($this->status_code)) {
            return 'Unknown';
        }

        $statusText = HttpResponse::$statusTexts[$this->status_code] ?? null;

        return 'HTTP '.$this->status_code.($statusText ? ' '.$statusText : '');
    }

    /**
     * Why this check is considered a failure, for use in alert messages. Falls
     * back to the status label when the status is reason enough on its own.
     */
    public function failureReasonFor(Site $site): ?string
    {
        if ($this->isConnectionError()) {
            return Str::limit(Str::of($this->response_message)->trim()->explode("\n")->first(), 160);
        }

        if (is_null($this->status_code) || $this->status_code >= 400) {
            return $this->statusLabel();
        }

        if ($this->response_time >= $site->down_threshold) {
            return 'Too slow: '.number_format($this->response_time).' ms (down threshold '
                .number_format($site->down_threshold).' ms)';
        }

        return null;
    }
}
