<?php

/**
 * Plugin Name:       Best Odds Table
 * Description:       Compare live odds from multiple bookmakers with a configurable Gutenberg block.
 * Version:           0.4.0
 * Requires at least: 6.6
 * Requires PHP:      8.1
 * Author:            Radoslav Zdravković
 * License:           GPL-2.0-or-later
 * Text Domain:       best-odds-table
 *
 * The bootstrap is intentionally thin: it guards direct access, loads the
 * Composer autoloader and hands control to the Plugin class. All behaviour
 * lives in namespaced classes under src/.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

$bestOddsTableAutoload = __DIR__ . '/vendor/autoload.php';

if (!is_readable($bestOddsTableAutoload)) {
    add_action('admin_notices', static function (): void {
        echo '<div class="notice notice-error"><p>'
            . esc_html__(
                'Best Odds Table: dependencies are missing. Run "composer install" in the plugin directory.',
                'best-odds-table'
            )
            . '</p></div>';
    });

    return;
}

require_once $bestOddsTableAutoload;

\BestOddsTable\Plugin::boot(__FILE__);

register_activation_hook(__FILE__, [\BestOddsTable\Plugin::class, 'activate']);
register_deactivation_hook(__FILE__, [\BestOddsTable\Plugin::class, 'deactivate']);
