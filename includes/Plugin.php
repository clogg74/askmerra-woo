<?php

declare(strict_types=1);

namespace AskMerra\WooCommerce;

/**
 * The plugin: a small service container and the modules. Services are built once, when first
 * needed; each module declares its own factories (ModuleInterface::services()).
 */
final class Plugin
{
    /** Modules, in the order their hooks are registered. */
    private const MODULES = [
        Api\Module::class,
        Catalog\Module::class,
        Feed\Module::class,
        Sync\Module::class,
        Admin\Module::class,
        Frontend\Module::class,
        Cli\Module::class,
    ];

    private static ?self $instance = null;

    /** @var ModuleInterface[] */
    private array $modules = [];

    /** @var array<string, callable(Plugin): object> */
    private array $factories = [];

    /** @var array<string, object> */
    private array $services = [];

    private bool $booted = false;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    private function __construct()
    {
        $this->factories = [
            Config::class => static fn (): Config => new Config(new Crypto()),
            Crypto::class => static fn (): Crypto => new Crypto(),
            StorefrontRepository::class => static fn (Plugin $plugin): StorefrontRepository => new StorefrontRepository(
                $plugin->get(Config::class)
            ),
            Log\Logger::class => static fn (Plugin $plugin): Log\Logger => new Log\Logger($plugin->get(Config::class)),
        ];

        foreach (self::MODULES as $class) {
            if (class_exists($class)) {
                $module = new $class();
                $this->modules[] = $module;
                $this->factories += $module->services();
            }
        }
    }

    /**
     * @template T of object
     * @param class-string<T> $id
     * @return T
     */
    public function get(string $id): object
    {
        if (!isset($this->services[$id])) {
            if (!isset($this->factories[$id])) {
                throw new \LogicException(sprintf('AskMerra: no service %s.', $id));
            }

            $this->services[$id] = ($this->factories[$id])($this);
        }

        return $this->services[$id];
    }

    public function has(string $id): bool
    {
        return isset($this->factories[$id]);
    }

    public function boot(): void
    {
        if ($this->booted) {
            return;
        }

        $this->booted = true;
        Install::maybeUpgrade();

        foreach ($this->modules as $module) {
            $module->register($this);
        }
    }
}
