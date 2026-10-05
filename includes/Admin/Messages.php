<?php

declare(strict_types=1);

namespace AskMerra\WooCommerce\Admin;

/**
 * Messages that survive the redirect after an action (status page buttons, bulk action), kept per
 * user for a few minutes and shown once by Notices.
 */
final class Messages
{
    private const TRANSIENT = 'askmerra_admin_messages_';

    public const SUCCESS = 'success';
    public const ERROR = 'error';
    public const WARNING = 'warning';
    public const INFO = 'info';

    public function add(string $type, string $text): void
    {
        $messages = $this->peek();
        $messages[] = ['type' => $type, 'text' => $text];
        set_transient($this->key(), $messages, 5 * MINUTE_IN_SECONDS);
    }

    /** @return array<int, array{type: string, text: string}> the messages, removed from storage */
    public function pull(): array
    {
        $messages = $this->peek();

        if ($messages) {
            delete_transient($this->key());
        }

        return $messages;
    }

    /** @param array<int, array{type: string, text: string}> $messages */
    public static function render(array $messages): void
    {
        foreach ($messages as $message) {
            printf(
                '<div class="notice notice-%s is-dismissible askmerra-notice"><p>%s</p></div>',
                esc_attr($message['type']),
                esc_html($message['text'])
            );
        }
    }

    /** @return array<int, array{type: string, text: string}> */
    private function peek(): array
    {
        $messages = get_transient($this->key());

        return is_array($messages) ? $messages : [];
    }

    private function key(): string
    {
        return self::TRANSIENT . get_current_user_id();
    }
}
