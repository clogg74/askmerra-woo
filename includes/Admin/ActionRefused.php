<?php

declare(strict_types=1);

namespace AskMerra\WooCommerce\Admin;

/** An admin action that cannot run as asked (unknown storefront, not syncing...): shown, not logged. */
final class ActionRefused extends \RuntimeException
{
}
