<?php

declare(strict_types=1);

namespace BestOddsTable\Storage;

/**
 * Lock on the options table, atomic through the option_name unique key.
 *
 * Same approach as WordPress core's WP_Upgrader::create_lock(): INSERT IGNORE
 * succeeds for exactly one process. add_option() is not enough: it checks
 * whether the option exists and then inserts with ON DUPLICATE KEY UPDATE, so
 * two processes that check at the same moment both "succeed", the second
 * overwriting the first.
 *
 * The row stores when the lock expires. Taking over an expired lock is a
 * conditional UPDATE on that old value, so of two processes that both see it
 * expired, only one wins (core uses a plain update_option() there).
 */
final class WpOptionLock implements Lock
{
    public function __construct(private readonly string $prefix = 'best_odds_table_lock_')
    {
    }

    public function acquire(string $name, int $ttl): bool
    {
        global $wpdb;

        $option = $this->prefix . $name;
        $now = time();

        $inserted = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO `{$wpdb->options}` (`option_name`, `option_value`, `autoload`) VALUES (%s, %s, 'off')",
            $option,
            (string) ($now + $ttl)
        ));

        if ($inserted) {
            return true;
        }

        // Read the row directly: the options cache may hold an older value.
        $expiresAt = $wpdb->get_var($wpdb->prepare(
            "SELECT `option_value` FROM `{$wpdb->options}` WHERE `option_name` = %s",
            $option
        ));

        if ($expiresAt === null || (int) $expiresAt > $now) {
            return false; // Held by a live run (or released and re-taken just now).
        }

        // Expired: take it over only if nobody else already did.
        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE `{$wpdb->options}` SET `option_value` = %s WHERE `option_name` = %s AND `option_value` = %s",
            (string) ($now + $ttl),
            $option,
            (string) $expiresAt
        ));

        return $updated === 1;
    }

    public function release(string $name): void
    {
        global $wpdb;

        $wpdb->delete($wpdb->options, ['option_name' => $this->prefix . $name]);
        wp_cache_delete($this->prefix . $name, 'options');
    }
}
