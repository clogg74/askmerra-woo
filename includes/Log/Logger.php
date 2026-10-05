<?php

declare(strict_types=1);

namespace AskMerra\WooCommerce\Log;

use AskMerra\WooCommerce\Config;

/**
 * Logs to WooCommerce > Status > Logs, source "askmerra". Errors are always logged; debug lines only
 * with the "Debug log" setting.
 */
final class Logger
{
    private const SOURCE = 'askmerra';

    public function __construct(private readonly Config $config)
    {
    }

    public function error(string $message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }

    public function info(string $message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    public function debug(string $message, array $context = []): void
    {
        if ($this->config->isDebug()) {
            $this->log('debug', $message, $context);
        }
    }

    private function log(string $level, string $message, array $context): void
    {
        if (!function_exists('wc_get_logger')) {
            return;
        }

        if (isset($context['exception']) && $context['exception'] instanceof \Throwable) {
            $exception = $context['exception'];
            $context['exception'] = get_class($exception) . ': ' . $exception->getMessage() . ' @ ' . $exception->getFile() . ':' . $exception->getLine();
        }

        wc_get_logger()->log($level, $message, $context + ['source' => self::SOURCE]);
    }
}
