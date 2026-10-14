<?php

/**
 * Runs when the plugin is deleted in wp-admin (not on deactivation).
 *
 * Removes everything the plugin stored: settings, watch list, odds snapshots,
 * the event index, locks, its transients and its scheduled jobs. On multisite
 * it cleans every site. Odds in a persistent object cache are not listed by
 * WordPress, so those simply expire.
 */

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

/**
 * @internal Cleans the current site.
 */
function best_odds_table_uninstall_site(): void
{
    global $wpdb;

    // Every option the plugin writes starts with this prefix (WpOptionStore, WpOptionLock).
    $wpdb->query($wpdb->prepare(
        "DELETE FROM `{$wpdb->options}` WHERE `option_name` LIKE %s",
        $wpdb->esc_like('best_odds_table_') . '%'
    ));

    // Transients use the short "oc_" prefix, so only the plugin's own keys are matched.
    foreach (['oc_odds_', 'oc_events_index', 'oc_backoff_until', 'oc_fetch_lock'] as $key) {
        foreach (['_transient_', '_transient_timeout_'] as $type) {
            $wpdb->query($wpdb->prepare(
                "DELETE FROM `{$wpdb->options}` WHERE `option_name` LIKE %s",
                $wpdb->esc_like($type . $key) . '%'
            ));
        }
    }

    wp_clear_scheduled_hook('best_odds_table_fetch');
    wp_clear_scheduled_hook('best_odds_table_refresh_index');

    // Rows were deleted with SQL, so drop the cached option lists too.
    wp_cache_delete('alloptions', 'options');
    wp_cache_delete('notoptions', 'options');
}

if (is_multisite()) {
    foreach (get_sites(['fields' => 'ids', 'number' => 0]) as $siteId) {
        switch_to_blog((int) $siteId);
        best_odds_table_uninstall_site();
        restore_current_blog();
    }
} else {
    best_odds_table_uninstall_site();
}
