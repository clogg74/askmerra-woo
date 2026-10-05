<?php

declare(strict_types=1);

namespace AskMerra\WooCommerce\Sync;

use AskMerra\WooCommerce\Api\Client;
use AskMerra\WooCommerce\Catalog\ProductBuilder;
use AskMerra\WooCommerce\Config;
use AskMerra\WooCommerce\Feed\FeedFlags;
use AskMerra\WooCommerce\Feed\FeedGenerator;
use AskMerra\WooCommerce\Log\Logger;
use AskMerra\WooCommerce\ModuleInterface;
use AskMerra\WooCommerce\Plugin;
use AskMerra\WooCommerce\StorefrontRepository;

/**
 * The sync engine: change listening, the queue and what AskMerra has, and the background jobs.
 */
final class Module implements ModuleInterface
{
    /** Background job => Jobs method. */
    private const JOBS = [
        Scheduler::HOOK_PROCESS => 'processQueue',
        Scheduler::HOOK_STOCK => 'checkStock',
        Scheduler::HOOK_MODIFIED => 'checkModified',
        Scheduler::HOOK_DAILY => 'daily',
        Scheduler::HOOK_FEED => 'generateFeeds',
    ];

    public function services(): array
    {
        return [
            Queue::class => static fn (): Queue => new Queue(),
            State::class => static fn (): State => new State(),
            RunLog::class => static fn (): RunLog => new RunLog(),
            Problems::class => static fn (): Problems => new Problems(),
            Enqueuer::class => static fn (Plugin $p): Enqueuer => new Enqueuer(
                $p->get(StorefrontRepository::class),
                $p->get(Queue::class)
            ),
            ChangeListener::class => static fn (Plugin $p): ChangeListener => new ChangeListener(
                $p->get(Enqueuer::class),
                $p->get(Config::class)
            ),
            MethodTracker::class => static fn (Plugin $p): MethodTracker => new MethodTracker(
                $p->get(Config::class),
                $p->get(State::class),
                $p->get(FeedFlags::class),
                $p->get(FeedGenerator::class)
            ),
            Reconciler::class => static fn (Plugin $p): Reconciler => new Reconciler(
                $p->get(ProductBuilder::class),
                $p->get(MethodTracker::class),
                $p->get(State::class),
                $p->get(Queue::class),
                $p->get(RunLog::class)
            ),
            QueueProcessor::class => static fn (Plugin $p): QueueProcessor => new QueueProcessor(
                $p->get(Config::class),
                $p->get(StorefrontRepository::class),
                $p->get(Queue::class),
                $p->get(State::class),
                $p->get(Problems::class),
                $p->get(ProductBuilder::class),
                $p->get(Client::class),
                $p->get(FeedFlags::class),
                $p->get(Reconciler::class),
                $p->get(Logger::class)
            ),
            StockChecker::class => static fn (Plugin $p): StockChecker => new StockChecker(
                $p->get(ProductBuilder::class),
                $p->get(State::class),
                $p->get(Queue::class)
            ),
            Remover::class => static fn (Plugin $p): Remover => new Remover(
                $p->get(Config::class),
                $p->get(State::class),
                $p->get(Queue::class),
                $p->get(RunLog::class),
                $p->get(QueueProcessor::class),
                $p->get(FeedGenerator::class),
                $p->get(FeedFlags::class)
            ),
            Scheduler::class => static fn (Plugin $p): Scheduler => new Scheduler(
                $p->get(Config::class),
                $p->get(StorefrontRepository::class),
                $p->get(Queue::class),
                $p->get(Logger::class)
            ),
            Jobs::class => static fn (Plugin $p): Jobs => new Jobs(
                $p->get(Config::class),
                $p->get(StorefrontRepository::class),
                $p->get(QueueProcessor::class),
                $p->get(Queue::class),
                $p->get(Reconciler::class),
                $p->get(StockChecker::class),
                $p->get(Enqueuer::class),
                $p->get(RunLog::class),
                $p->get(Scheduler::class),
                $p->get(FeedGenerator::class),
                $p->get(FeedFlags::class),
                $p->get(Logger::class)
            ),
            Status::class => static fn (Plugin $p): Status => new Status(
                $p->get(Config::class),
                $p->get(StorefrontRepository::class),
                $p->get(State::class),
                $p->get(Queue::class),
                $p->get(RunLog::class),
                $p->get(Problems::class),
                $p->get(FeedGenerator::class),
                $p->get(FeedFlags::class),
                $p->get(Scheduler::class)
            ),
        ];
    }

    public function register(Plugin $plugin): void
    {
        $plugin->get(ChangeListener::class)->register();
        $plugin->get(Scheduler::class)->register();

        foreach (self::JOBS as $hook => $method) {
            add_action($hook, static function () use ($plugin, $method): void {
                self::guard($plugin, static fn () => $plugin->get(Jobs::class)->$method());
            });
        }

        add_action('askmerra_rescheduled', static function ($started = [], $stopped = []) use ($plugin): void {
            self::guard($plugin, static fn () => $plugin->get(Jobs::class)->onRescheduled((array) $started, (array) $stopped));
        }, 10, 2);

        add_action('askmerra_settings_saved', static function ($changed = []) use ($plugin): void {
            self::guard($plugin, static fn () => $plugin->get(Jobs::class)->onSettingsSaved(array_map('strval', (array) $changed)));
        });
    }

    /** Runs sync work from a hook: a failure is logged, never thrown into the page, WP-CLI or Action Scheduler. */
    private static function guard(Plugin $plugin, callable $work): void
    {
        try {
            $work();
        } catch (\Throwable $e) {
            try {
                $plugin->get(Logger::class)->error('Sync: ' . $e->getMessage(), ['exception' => $e]);
            } catch (\Throwable) {
                // Logging is not available: nothing else to do.
            }
        }
    }
}
