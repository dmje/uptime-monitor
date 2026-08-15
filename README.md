# Uptime Monitor

Uptime Monitor is a self-hosted web monitoring tool, built with laravel.

## Features

- Monitor your web uptime per minutes (or any time interval)
- Record response time on each web
- Show uptime badges in 3 colors: green for up, yellow for warning, red for down, based on response time
- Send a telegram notification when a site goes down, and again when it comes back up
- Only alert once a site has failed several checks in a row, so a single blip stays quiet
- Remind you about a site that stays down on a backing off schedule, instead of every few minutes

## Why I need this?

- Open-source, modify as you need
- Self-hosted, deploy on your own server
- Store and control your monitoring logs yourself
- Let you know when your websites are down
- For freelancer/agency, increase your client's trust because you monitor their website

## How to Install

### Server Requirements

This application can be installed on local server and online server with these specifications:

1. PHP 8.1 (and meet [Laravel 10.x requirements](https://laravel.com/docs/10.x/deployment#server-requirements)).
2. MySQL or MariaDB Database.
3. SQLite (for automated testing).

### Installation Steps

1. Clone repository: `git clone https://github.com/nafiesl/uptime-monitor.git`
1. `$ cd uptime-monitor`
1. Install PHP dependencies: `$ composer install`
1. Install javscript dependencies: `$ npm install`
1. Copy `.env.example` to `.env`: `$ cp .env.example .env`
1. Generate application key: `$ php artisan key:generate`
1. Create a MySQL or MariaDB database.
1. Configure database and environment variables `.env`.
    ```
    APP_URL=http://localhost:8000
    APP_TIMEZOME="Asia/Jakarta"

    DB_DATABASE=homestead
    DB_USERNAME=homestead
    DB_PASSWORD=secret

    TELEGRAM_NOTIFER_TOKEN=
    ```
1. Run database migration: `$ php artisan migrate --seed`
1. Build assets: `$ npm run build`
1. Run task scheduler: `$ php artisan schedule:work`
1. Start server in a separeted terminal tab: `$ php artisan serve`
1. Open the web app: http://localhost:8000.
1. Login using default user credential:
    - Email: `admin@example.net`
    - Password: `password`
1. Go to **Customer Site** menu.
1. Add some new customer sites (name and URL).
1. After adding customer sites, go to **Dashboard**
1. Click **Start Monitoring** to update the uptime badge per minute.

### Telegram Notifier Setup

In order to get notified in Telegram when the customer sites are down, we need to use a Telegram Bot and a Chat ID

1. Create a Telegram Bot ([how to](https://gist.github.com/nafiesl/4ad622f344cd1dc3bb1ecbe468ff9f8a#create-a-telegram-bot-and-get-a-bot-token))
1. Get a Chat ID of the Telegram Bot ([how to](https://gist.github.com/nafiesl/4ad622f344cd1dc3bb1ecbe468ff9f8a#get-chat-id-for-a-private-chat))
1. Update `.env` file, set `TELEGRAM_NOTIFER_TOKEN=your_telegram_bot_token`
1. Set our Chat ID in the Profile Page.
    - Go to User Profile Menu
    - Click Edit Profile
    - Fill the Telegram Chat ID field with `your_chat_id`
    - Click Update Profile
    - Click **Test Telegram Chat** to test the telegram configuration
### How Alerting Works

Alerts follow the status of a site, not each individual check:

1. A check counts as **failed** when the request throws, the site answers with a
   4xx or 5xx status, or the response is slower than the site Down Threshold.
1. A site is only marked **down** after `Down Confirmations` failed checks in a
   row (default 2), and back **up** after `Up Confirmations` successful checks in
   a row (default 2). A single slow or failed check therefore raises nothing.
1. You get one **🔴 DOWN** message when a site is marked down, and one
   **🟢 BACK UP** message when it recovers, with the outage length.
1. While a site stays down you get **🔴 STILL DOWN** reminders on a backing off
   schedule, based on the `Reminder Interval` field: after 1x, 3x and 12x the
   interval, then every 6 hours. With the default 5 minute interval that is
   5 minutes, 15 minutes, 1 hour, then every 6 hours.
1. Every message names the status that caused it, for example
   `Status: HTTP 502 Bad Gateway` or `No response: cURL error 6 ...`, along with
   the last few checks.

These are all per site settings:

- Go to Site menu
- Select one of the sites and click Edit link
- Set the Reminder Interval field, between 0 and 60. Set it to 0 to get down and
  back up alerts only, with no reminders in between.
- Set the Down Confirmations and Up Confirmations fields, between 1 and 10.

## Screenshot

#### Dashboard
![screen_2023-12-20_004](https://github.com/nafiesl/uptime-monitor/assets/8721551/7b115df3-f2c0-467e-ba1e-b488c0452bc1)
#### Dashboard in mobile device
![screen_2023-12-20_009](https://github.com/nafiesl/uptime-monitor/assets/8721551/11173d6f-437d-49b0-a509-2ddeb7e69b7e)
#### Monitoring graph on customer site detail
![screen_2023-12-20_005](https://github.com/nafiesl/uptime-monitor/assets/8721551/4f412aaf-8848-484b-8ad8-a625898ea187)
#### Monitoring log tab on customer site detail
![screen_2023-12-20_006](https://github.com/nafiesl/uptime-monitor/assets/8721551/2cbbda3c-a13c-4818-8ab7-25ca0ad04b53)
#### User profile menu
![screen_2023-12-20_007](https://github.com/nafiesl/uptime-monitor/assets/8721551/6f352dc4-bfbe-4b1a-8d0e-ee5df4e97ca1)
#### Telegram notification sample
![screen_2023-12-20_008](https://github.com/nafiesl/uptime-monitor/assets/8721551/15ebca99-d920-4764-a567-06e2e1b748df)

## Lisensi

Uptime Monitor project is an open-sourced software licensed under the [Lisensi MIT](LICENSE).
