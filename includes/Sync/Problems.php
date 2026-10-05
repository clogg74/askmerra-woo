<?php

declare(strict_types=1);

namespace AskMerra\WooCommerce\Sync;

/**
 * Problems that need the merchant: a rejected key, a suspended AskMerra shop. Shown as an admin
 * notice and on the status page until the next successful call.
 */
final class Problems
{
    private const OPTION = 'askmerra_problems';

    public function set(string $storefrontId, string $message): void
    {
        $problems = $this->all();
        $problems[$storefrontId] = [
            'message' => $message,
            'since' => $problems[$storefrontId]['since'] ?? gmdate('Y-m-d H:i:s'),
        ];
        update_option(self::OPTION, $problems, false);
    }

    public function clear(string $storefrontId): void
    {
        $problems = $this->all();

        if (isset($problems[$storefrontId])) {
            unset($problems[$storefrontId]);
            update_option(self::OPTION, $problems, false);
        }
    }

    /** @return array<string, array{message: string, since: string}> by storefront id; since in UTC */
    public function all(): array
    {
        $problems = Db::freshOption(self::OPTION, []);

        return is_array($problems) ? $problems : [];
    }
}
