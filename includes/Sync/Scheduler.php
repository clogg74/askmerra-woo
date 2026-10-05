<?php

declare(strict_types=1);

namespace AskMerra\WooCommerce\Sync;

use AskMerra\WooCommerce\Config;
use AskMerra\WooCommerce\Log\Logger;
use AskMerra\WooCommerce\StorefrontRepository;

/**
 * The background jobs, in Action Scheduler (part of WooCommerce), group "askmerra":
 *
 * - askmerra_process_queue: sends the queue. Scheduled when products are queued and again after a
 *   run while products wait (at the time the next one is due), so an idle shop runs nothing.
 * - askmerra_stock_check and askmerra_modified_check: every 15 minutes.
 * - askmerra_daily: the daily check (or the full rebuild, on its days) at the daily time.
 * - askmerra_feed_generate: the feed files, at the feed frequency (feed storefronts only).
 *
 * The recurring jobs exist only while a storefront syncs. They follow the settings by themselves:
 * what is scheduled is compared with what the settings ask for on admin, cron and WP-CLI requests.
 */
final class Scheduler
{
    public const HOOK_PROCESS = 'askmerra_process_queue';
    public const HOOK_STOCK = 'askmerra_stock_check';
    public const HOOK_MODIFIED = 'askmerra_modified_check';
    public const HOOK_DAILY = 'askmerra_daily';
    public const HOOK_FEED = 'askmerra_feed_generate';
    public const GROUP = 'askmerra';

    /** What is scheduled: plan signature, syncing storefronts, when it was scheduled and last verified. Autoloaded: read on admin requests. */
    private const OPTION = 'askmerra_schedule';

    /** Unix time of the last queue run and of the last 15-minute check (Jobs). */
    public const OPTION_LAST_QUEUE_RUN = 'askmerra_last_queue_run';
    public const OPTION_LAST_CHECK_RUN = 'askmerra_last_check_run';

    private const CHECK_INTERVAL = 900;

    /** The feed frequency (minutes) as a cron expression; daily runs at 02:05 site time. */
    private const FEED_CRON = [
        60 => '5 * * * *',
        180 => '5 */3 * * *',
        360 => '5 */6 * * *',
        480 => '5 */8 * * *',
        720 => '5 */12 * * *',
    ];

    /** A queue run is scheduled for this time (or earlier), as found at $armCheckedAt: saves queries when a request queues many times. */
    private ?int $armedAt = null;

    private int $armCheckedAt = 0;

    public function __construct(
        private readonly Config $config,
        private readonly StorefrontRepository $storefronts,
        private readonly Queue $queue,
        private readonly Logger $logger
    ) {
    }

    public function register(): void
    {
        add_action('action_scheduler_init', [$this, 'maybeEnsureScheduled']);
        add_action('askmerra_queue_changed', [$this, 'onQueueChanged']);
    }

    /** Admin, cron and WP-CLI requests keep the schedule in line with the settings; shoppers' requests never pay for it. */
    public function maybeEnsureScheduled(): void
    {
        if (!is_admin() && !wp_doing_cron() && !(defined('WP_CLI') && WP_CLI)) {
            return;
        }

        try {
            $this->ensureScheduled();
        } catch (\Throwable $e) {
            $this->logger->error('Scheduling the background jobs: ' . $e->getMessage(), ['exception' => $e]);
        }
    }

    /**
     * Schedules the jobs again when the settings ask for something else than what is scheduled
     * (sync turned on or off, push or feed, daily time, feed frequency, the site's time zone offset),
     * and, at most hourly, checks that they still exist on the schedule the settings ask for.
     */
    public function ensureScheduled(): void
    {
        if (!$this->isAvailable()) {
            return;
        }

        $stored = get_option(self::OPTION, []);
        $stored = is_array($stored) ? $stored : [];
        $plan = $this->plan();

        if (($stored['signature'] ?? null) !== $this->signature($plan) || get_option('askmerra_needs_scheduling') === 'yes') {
            $this->reschedule();

            return;
        }

        if ((int) ($stored['checked_at'] ?? 0) > time() - HOUR_IN_SECONDS) {
            return;
        }

        foreach ($plan as $hook => $schedule) {
            if (!$this->isScheduledAsPlanned($hook, $schedule)) {
                $this->reschedule();

                return;
            }
        }

        // A queue run that died leaves products waiting with nothing scheduled.
        $this->scheduleNextRun();
        $stored['checked_at'] = time();
        update_option(self::OPTION, $stored, true);
    }

    /**
     * Schedules every job again from the settings. Fires 'askmerra_rescheduled' with the storefronts
     * that started and stopped syncing since the last time.
     */
    public function reschedule(): void
    {
        if (!$this->isAvailable()) {
            return;
        }

        $stored = get_option(self::OPTION, []);
        $stored = is_array($stored) ? $stored : [];
        $plan = $this->plan();

        foreach ([self::HOOK_STOCK, self::HOOK_MODIFIED, self::HOOK_DAILY, self::HOOK_FEED] as $hook) {
            as_unschedule_all_actions($hook, [], self::GROUP);
        }

        foreach ($plan as $hook => $schedule) {
            isset($schedule['cron'])
                ? as_schedule_cron_action(time(), $schedule['cron'], $hook, [], self::GROUP, true)
                : as_schedule_recurring_action(time() + $schedule['delay'], $schedule['interval'], $hook, [], self::GROUP, true);
        }

        $syncing = array_map('strval', array_keys($this->storefronts->syncing()));
        $previous = array_map('strval', (array) ($stored['syncing'] ?? []));

        update_option(self::OPTION, [
            'signature' => $this->signature($plan),
            'syncing' => $syncing,
            'scheduled_at' => time(),
            'checked_at' => time(),
        ], true);
        delete_option('askmerra_needs_scheduling');

        if ($syncing) {
            // The run notices storefronts that switched between Push API and feed, or start.
            $this->armQueue(time());
        } else {
            as_unschedule_all_actions(self::HOOK_PROCESS, [], self::GROUP);
            $this->armedAt = null;
        }

        do_action(
            'askmerra_rescheduled',
            array_values(array_diff($syncing, $previous)),
            array_values(array_diff($previous, $syncing))
        );
    }

    /** Removes every AskMerra job (deactivation, uninstall); the next activation schedules them again. */
    public static function unscheduleAll(): void
    {
        if (function_exists('as_unschedule_all_actions') && class_exists(\ActionScheduler::class) && \ActionScheduler::is_initialized()) {
            as_unschedule_all_actions('', [], self::GROUP);
        }

        delete_option(self::OPTION);
    }

    /**
     * Whether the background jobs run: false when queued products have been due for 15 minutes
     * without a queue run, or the 15-minute check has not run for 30 minutes.
     */
    public function isRunning(): bool
    {
        $syncing = array_map('strval', array_keys($this->storefronts->syncing()));

        if (!$syncing) {
            return true;
        }

        $now = time();
        $nextDue = $this->queue->getNextDueAt($syncing);
        $lastRun = (int) get_option(self::OPTION_LAST_QUEUE_RUN, 0);

        if ($nextDue !== null && $nextDue < $now - 900 && $lastRun < $now - 900) {
            return false;
        }

        $stored = get_option(self::OPTION, []);
        $since = max((int) get_option(self::OPTION_LAST_CHECK_RUN, 0), (int) (is_array($stored) ? ($stored['scheduled_at'] ?? 0) : 0));

        return $since > $now - 2 * self::CHECK_INTERVAL;
    }

    /** New products in the queue: make sure a run is scheduled now. */
    public function onQueueChanged(): void
    {
        try {
            $this->armQueue(time());
        } catch (\Throwable $e) {
            // The hourly check and the 15-minute check schedule it.
            $this->logger->error('Scheduling a queue run: ' . $e->getMessage(), ['exception' => $e]);
        }
    }

    /**
     * After a run, or as a safety net: schedules the next run when products wait - now when they
     * are due, else when the first of them is.
     */
    public function scheduleNextRun(int $notBefore = 0): void
    {
        $nextDue = $this->queue->getNextDueAt(array_map('strval', array_keys($this->storefronts->syncing())));

        if ($nextDue !== null) {
            $this->armQueue(max($nextDue, $notBefore, time()), true);
        }
    }

    /**
     * Makes sure a queue run is scheduled for $timestamp or earlier.
     *
     * @param bool $force check again even if this request already scheduled one (after a run)
     */
    private function armQueue(int $timestamp, bool $force = false): void
    {
        if (!$force && $this->armedAt !== null && $this->armedAt <= $timestamp && $this->armCheckedAt > time() - MINUTE_IN_SECONDS) {
            return;
        }

        if (!$this->isAvailable()) {
            return;
        }

        // Pending runs only: the run calling this is "in progress" and finishes right after.
        $pending = as_get_scheduled_actions([
            'hook' => self::HOOK_PROCESS,
            'group' => self::GROUP,
            'status' => \ActionScheduler_Store::STATUS_PENDING,
            'orderby' => 'date',
            'order' => 'ASC',
            'per_page' => 1,
        ]);
        $action = $pending ? reset($pending) : null;
        $date = $action instanceof \ActionScheduler_Action ? $action->get_schedule()->get_date() : null;

        $this->armCheckedAt = time();

        if ($action !== null && ($date === null || $date->getTimestamp() <= $timestamp)) {
            $this->armedAt = $date === null ? time() : $date->getTimestamp();

            return;
        }

        if ($action !== null) {
            as_unschedule_action(self::HOOK_PROCESS, [], self::GROUP);
        }

        as_schedule_single_action($timestamp, self::HOOK_PROCESS, [], self::GROUP);
        $this->armedAt = $timestamp;
    }

    /**
     * The recurring jobs the settings ask for: none while no storefront syncs.
     *
     * @return array<string, array{interval?: int, delay?: int, cron?: string}>
     */
    private function plan(): array
    {
        if (!$this->storefronts->syncing()) {
            return [];
        }

        [$hour, $minute] = $this->config->getDailyTime();
        $plan = [
            self::HOOK_STOCK => ['interval' => self::CHECK_INTERVAL, 'delay' => self::CHECK_INTERVAL],
            self::HOOK_MODIFIED => ['interval' => self::CHECK_INTERVAL, 'delay' => (int) (self::CHECK_INTERVAL / 2)],
            self::HOOK_DAILY => ['cron' => $this->dailyCron($hour, $minute)],
        ];

        if ($this->storefronts->feeding()) {
            $frequency = $this->config->getFeedFrequency();
            $plan[self::HOOK_FEED] = ['cron' => self::FEED_CRON[$frequency] ?? $this->dailyCron(2, 5)];
        }

        return $plan;
    }

    /**
     * Whether a job is scheduled once, on the schedule the plan asks for. A job running while its
     * schedule changes is not replaced (one at a time) and, when it ends, Action Scheduler plans its
     * next run from the old schedule: that run is replaced here. A running job is compared once it
     * has planned its next run.
     *
     * @param array{interval?: int, delay?: int, cron?: string} $schedule
     */
    private function isScheduledAsPlanned(string $hook, array $schedule): bool
    {
        $pending = as_get_scheduled_actions([
            'hook' => $hook,
            'group' => self::GROUP,
            'status' => \ActionScheduler_Store::STATUS_PENDING,
            'per_page' => 2,
        ]);

        if (!$pending) {
            return as_has_scheduled_action($hook, null, self::GROUP);
        }

        $action = reset($pending);
        $actual = count($pending) === 1 && $action instanceof \ActionScheduler_Action ? $action->get_schedule() : null;

        return isset($schedule['cron'])
            ? $actual instanceof \ActionScheduler_CronSchedule && $actual->get_recurrence() === $schedule['cron']
            : $actual instanceof \ActionScheduler_IntervalSchedule && (int) $actual->get_recurrence() === $schedule['interval'];
    }

    /**
     * A daily cron expression for a site-time hour and minute. Action Scheduler reads cron
     * expressions in UTC, so the time is converted with today's offset; when daylight saving time
     * changes the offset, the plan's signature changes and the job is scheduled again.
     */
    private function dailyCron(int $hour, int $minute): string
    {
        $local = (new \DateTimeImmutable('today', wp_timezone()))->setTime($hour, $minute);
        $utc = $local->setTimezone(new \DateTimeZone('UTC'));

        return sprintf('%d %d * * *', (int) $utc->format('i'), (int) $utc->format('G'));
    }

    private function signature(array $plan): string
    {
        return sha1((string) wp_json_encode([$plan, array_keys($this->storefronts->syncing())]));
    }

    private function isAvailable(): bool
    {
        return function_exists('as_schedule_single_action')
            && class_exists(\ActionScheduler::class)
            && \ActionScheduler::is_initialized();
    }
}
