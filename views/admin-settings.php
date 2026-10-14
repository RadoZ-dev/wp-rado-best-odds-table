<?php

/**
 * Settings page view. Receives $view from SettingsPage::render().
 * Every dynamic value is escaped at the point of output.
 *
 * @var array<string, mixed> $view
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

$f = $view['fields'];
$lastRun = is_array($view['lastRun']) ? $view['lastRun'] : null;

$notices = [
    'ok' => [__('Fetch completed.', 'best-odds-table'), 'success'],
    'locked' => [__('A fetch is already running. Try again in a minute.', 'best-odds-table'), 'warning'],
    'backing_off' => [__('The source asked us to slow down. Fetching resumes automatically.', 'best-odds-table'), 'warning'],
    'rate_limited' => [__('The source rate-limited this run. Remaining pairs will be fetched later.', 'best-odds-table'), 'warning'],
];
?>
<div class="wrap bot-settings">
    <h1><?php echo esc_html__('Best Odds Table', 'best-odds-table'); ?></h1>

    <?php if (isset($notices[$view['notice']])) : ?>
        <div class="notice notice-<?php echo esc_attr($notices[$view['notice']][1]); ?> is-dismissible">
            <p><?php echo esc_html($notices[$view['notice']][0]); ?></p>
        </div>
    <?php endif; ?>

    <div class="bot-status card">
        <h2><?php echo esc_html__('Status', 'best-odds-table'); ?></h2>
        <dl>
            <dt><?php echo esc_html__('Watched event/market pairs', 'best-odds-table'); ?></dt>
            <dd><?php echo esc_html((string) $view['watchedCount']); ?></dd>

            <dt><?php echo esc_html__('Last fetch', 'best-odds-table'); ?></dt>
            <dd>
                <?php if ($lastRun) : ?>
                    <?php
                    echo esc_html(sprintf(
                        /* translators: 1: time ago, 2: status, 3: fetched, 4: failed, 5: deferred */
                        __('%1$s ago: %2$s (%3$d fetched, %4$d failed, %5$d deferred)', 'best-odds-table'),
                        human_time_diff((int) $lastRun['at']),
                        (string) $lastRun['status'],
                        (int) $lastRun['fetched'],
                        (int) $lastRun['failed'],
                        (int) $lastRun['deferred']
                    ));
                    ?>
                <?php else : ?>
                    <?php echo esc_html__('Not run yet', 'best-odds-table'); ?>
                <?php endif; ?>
            </dd>

            <dt><?php echo esc_html__('Next scheduled fetch', 'best-odds-table'); ?></dt>
            <dd>
                <?php
                echo $view['nextRun']
                    ? esc_html(sprintf(__('in %s', 'best-odds-table'), human_time_diff(time(), (int) $view['nextRun'])))
                    : esc_html__('Not scheduled', 'best-odds-table');
                ?>
            </dd>
        </dl>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="<?php echo esc_attr($view['fetchAction']); ?>">
            <?php wp_nonce_field($view['fetchAction']); ?>
            <?php submit_button(__('Run scheduled fetch now', 'best-odds-table'), 'secondary', 'submit', false); ?>
        </form>
    </div>

    <form method="post" action="options.php">
        <?php settings_fields($view['group']); ?>

        <h2><?php echo esc_html__('Odds source', 'best-odds-table'); ?></h2>
        <p class="description">
            <?php echo esc_html__('Where odds come from. Other plugins can register more sources.', 'best-odds-table'); ?>
        </p>
        <select name="<?php echo esc_attr($f['source']); ?>">
            <?php foreach ($view['sources'] as $id => $label) : ?>
                <option value="<?php echo esc_attr((string) $id); ?>" <?php selected($view['activeSource'], (string) $id); ?>>
                    <?php echo esc_html($label); ?>
                </option>
            <?php endforeach; ?>
        </select>

        <h2><?php echo esc_html__('Markets', 'best-odds-table'); ?></h2>
        <p class="description">
            <?php echo esc_html__('Markets editors can choose in the block.', 'best-odds-table'); ?>
        </p>
        <fieldset>
            <?php foreach ($view['markets'] as $key => $label) : ?>
                <label class="bot-market">
                    <input type="checkbox"
                        name="<?php echo esc_attr($f['markets']); ?>[]"
                        value="<?php echo esc_attr((string) $key); ?>"
                        <?php checked(in_array($key, $view['enabledMarkets'], true)); ?>>
                    <?php echo esc_html($label); ?>
                </label>
            <?php endforeach; ?>
        </fieldset>

        <h2><?php echo esc_html__('Bookmakers', 'best-odds-table'); ?></h2>
        <p class="description">
            <?php echo esc_html__('Drag rows to set the default order. Disabled bookmakers are hidden in every block.', 'best-odds-table'); ?>
        </p>
        <table class="widefat striped bot-bookmakers">
            <thead>
                <tr>
                    <th class="bot-col-handle"><span class="screen-reader-text"><?php echo esc_html__('Order', 'best-odds-table'); ?></span></th>
                    <th class="bot-col-enabled"><?php echo esc_html__('Enabled', 'best-odds-table'); ?></th>
                    <th><?php echo esc_html__('Name', 'best-odds-table'); ?></th>
                    <th><?php echo esc_html__('Link (affiliate URL)', 'best-odds-table'); ?></th>
                    <th><?php echo esc_html__('Logo', 'best-odds-table'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($view['bookmakers'] as $row) : ?>
                    <?php
                    $bookmaker = $row['bookmaker'];
                    $base = $f['bookmakers'] . '[' . $bookmaker->id . ']';
                    ?>
                    <tr>
                        <td class="bot-col-handle">
                            <span class="dashicons dashicons-menu" aria-hidden="true"></span>
                            <input type="hidden" name="<?php echo esc_attr($base . '[id]'); ?>" value="<?php echo esc_attr($bookmaker->id); ?>">
                        </td>
                        <td class="bot-col-enabled">
                            <input type="checkbox"
                                name="<?php echo esc_attr($base . '[enabled]'); ?>"
                                value="1"
                                aria-label="<?php echo esc_attr(sprintf(__('Enable %s', 'best-odds-table'), $bookmaker->name)); ?>"
                                <?php checked($row['enabled']); ?>>
                        </td>
                        <td>
                            <input type="text" class="regular-text"
                                name="<?php echo esc_attr($base . '[name]'); ?>"
                                value="<?php echo esc_attr($bookmaker->name); ?>"
                                maxlength="60">
                        </td>
                        <td>
                            <input type="url" class="regular-text"
                                name="<?php echo esc_attr($base . '[url]'); ?>"
                                value="<?php echo esc_attr($bookmaker->url); ?>"
                                placeholder="https://">
                        </td>
                        <td>
                            <?php $hasLogo = (bool) $bookmaker->logoUrl; ?>
                            <div class="bot-logo-field">
                                <img class="bot-logo-preview" alt=""
                                    <?php echo $hasLogo ? 'src="' . esc_url($bookmaker->logoUrl) . '"' : 'hidden'; ?>>
                                <?php // The address is still what is saved; the Media Library fills it in. ?>
                                <input type="hidden" class="bot-logo-url"
                                    name="<?php echo esc_attr($base . '[logo]'); ?>"
                                    value="<?php echo esc_attr((string) $bookmaker->logoUrl); ?>">
                                <button type="button" class="button bot-logo-choose"
                                    data-title="<?php echo esc_attr(sprintf(
                                        /* translators: %s: bookmaker name. */
                                        __('Logo for %s', 'best-odds-table'),
                                        $bookmaker->name
                                    )); ?>"
                                    data-button="<?php echo esc_attr__('Use this logo', 'best-odds-table'); ?>"
                                    data-label-add="<?php echo esc_attr__('Upload logo', 'best-odds-table'); ?>"
                                    data-label-change="<?php echo esc_attr__('Change logo', 'best-odds-table'); ?>">
                                    <?php
                                    echo $hasLogo
                                        ? esc_html__('Change logo', 'best-odds-table')
                                        : esc_html__('Upload logo', 'best-odds-table');
                                    ?>
                                </button>
                                <button type="button" class="button-link button-link-delete bot-logo-remove"
                                    <?php echo $hasLogo ? '' : 'hidden'; ?>>
                                    <?php echo esc_html__('Remove', 'best-odds-table'); ?>
                                </button>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php submit_button(); ?>
    </form>
</div>
