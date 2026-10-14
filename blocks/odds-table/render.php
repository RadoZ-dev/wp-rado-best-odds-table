<?php

/**
 * Server render of best-odds-table/odds-table.
 *
 * Reads cached odds only; a page view never calls an odds source. Every cell
 * holds all three formats and CSS shows the one named in the wrapper's
 * data-format, so the format switch needs no request and works with full-page
 * caching. view.js only changes that attribute and remembers the choice.
 *
 * @var array<string, mixed> $attributes Block attributes.
 */

declare(strict_types=1);

use BestOddsTable\Admin\SettingsPage;
use BestOddsTable\Domain\OddsFormat;
use BestOddsTable\Plugin;

$view = Plugin::instance()->oddsTableBlock()->viewModel($attributes);

if ($view === null) {
    // Visitors get nothing rather than an empty box; editors get a hint.
    if (current_user_can('edit_posts')) {
        printf(
            '<div %s><p class="bot-empty">%s</p></div>',
            get_block_wrapper_attributes(), // phpcs:ignore -- Escaped by WordPress.
            esc_html__('Odds are not available yet for this event and market.', 'best-odds-table')
        );
    }
    return;
}

$formatLabels = [
    OddsFormat::Decimal->value => __('Decimal', 'best-odds-table'),
    OddsFormat::Fractional->value => __('Fractional', 'best-odds-table'),
    OddsFormat::American->value => __('American', 'best-odds-table'),
];
$selectId = wp_unique_id('bot-format-');
$startsAt = strtotime($view['event']['starts_at']);
$fetchedAt = strtotime($view['fetched_at']);
?>
<div
    <?php echo get_block_wrapper_attributes(['data-format' => $view['format']]); // phpcs:ignore -- Escaped by WordPress. ?>
    data-wp-interactive="best-odds-table"
    <?php echo wp_interactivity_data_wp_context(['format' => $view['format']]); // phpcs:ignore -- Escaped by WordPress. ?>
    data-wp-bind--data-format="context.format"
    data-wp-init="callbacks.restoreFormat"
>
    <div class="bot-header">
        <div>
            <p class="bot-event"><?php echo esc_html($view['event']['name']); ?></p>
            <p class="bot-meta">
                <?php echo esc_html(implode(' · ', array_filter([
                    $view['event']['competition'],
                    wp_date(get_option('date_format') . ' ' . get_option('time_format'), $startsAt),
                    $view['market']['label'],
                ]))); ?>
            </p>
        </div>
        <div class="bot-format">
            <label for="<?php echo esc_attr($selectId); ?>"><?php esc_html_e('Odds format', 'best-odds-table'); ?></label>
            <select id="<?php echo esc_attr($selectId); ?>" data-wp-on--change="actions.setFormat" data-wp-bind--value="context.format">
                <?php foreach ($formatLabels as $value => $label) : ?>
                    <option value="<?php echo esc_attr($value); ?>" <?php selected($view['format'], $value); ?>>
                        <?php echo esc_html($label); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <?php if ($view['stale']) : ?>
        <p class="bot-notice" role="status">
            <?php esc_html_e('These odds could not be refreshed recently and may be out of date.', 'best-odds-table'); ?>
        </p>
    <?php endif; ?>

    <?php if ($view['rows'] === []) : ?>
        <p class="bot-empty"><?php esc_html_e('No bookmaker offers this market yet.', 'best-odds-table'); ?></p>
    <?php else : ?>
        <div class="bot-scroll">
            <table class="bot-table">
                <caption class="screen-reader-text">
                    <?php
                    echo esc_html(sprintf(
                        /* translators: 1: market name, 2: "Home v Away". */
                        __('%1$s odds for %2$s by bookmaker', 'best-odds-table'),
                        $view['market']['label'],
                        $view['event']['name']
                    ));
                    ?>
                </caption>
                <thead>
                    <tr>
                        <th scope="col"><?php esc_html_e('Bookmaker', 'best-odds-table'); ?></th>
                        <?php foreach ($view['outcomes'] as $outcome) : ?>
                            <th scope="col"><?php echo esc_html($outcome['label']); ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($view['rows'] as $row) : ?>
                        <tr>
                            <th scope="row" class="bot-bookmaker">
                                <?php
                                // Rows without a logo show only the name.
                                $logo = empty($row['bookmaker']['logo']) ? '' : sprintf(
                                    '<img class="bot-logo" src="%s" alt="" loading="lazy" decoding="async" %s>',
                                    esc_url($row['bookmaker']['logo']),
                                    'data-wp-init="callbacks.hideBrokenLogo"'
                                );

                                // Built as one string so no template whitespace ends up inside the link.
                                $name = $logo . '<span>' . esc_html($row['bookmaker']['name']) . '</span>';

                                if ($row['bookmaker']['url'] === '') {
                                    echo '<span class="bot-bookmaker-label">' . $name . '</span>'; // phpcs:ignore -- Escaped above.
                                } else {
                                    printf(
                                        '<a href="%s" target="_blank" rel="sponsored nofollow noopener">%s</a>',
                                        esc_url($row['bookmaker']['url']),
                                        $name // phpcs:ignore -- Escaped above.
                                    );
                                }
                                ?>
                            </th>
                            <?php foreach ($view['outcomes'] as $outcome) : ?>
                                <?php $cell = $row['prices'][$outcome['key']] ?? null; ?>
                                <?php if ($cell === null) : ?>
                                    <td class="bot-price is-missing">
                                        <span aria-hidden="true">–</span>
                                        <span class="screen-reader-text"><?php esc_html_e('No price', 'best-odds-table'); ?></span>
                                    </td>
                                <?php else : ?>
                                    <?php
                                    $suspectHint = __(
                                        'This price is very different from the other bookmakers. Check it on the bookmaker\'s site.',
                                        'best-odds-table'
                                    );
                                    $cellClass = 'bot-price' . ($cell['best'] ? ' is-best' : '') . ($cell['suspect'] ? ' is-suspect' : '');
                                    ?>
                                    <td class="<?php echo esc_attr($cellClass); ?>"
                                        <?php echo $cell['suspect'] ? 'title="' . esc_attr($suspectHint) . '"' : ''; ?>>
                                        <?php foreach ($cell['formats'] as $format => $value) : ?>
                                            <span class="bot-odds bot-odds--<?php echo esc_attr($format); ?>"><?php echo esc_html($value); ?></span>
                                        <?php endforeach; ?>
                                        <?php if ($cell['best']) : ?>
                                            <span class="screen-reader-text"><?php esc_html_e('(best price)', 'best-odds-table'); ?></span>
                                        <?php endif; ?>
                                        <?php if ($cell['suspect']) : ?>
                                            <span class="bot-flag" aria-hidden="true">!</span>
                                            <span class="screen-reader-text"><?php echo esc_html($suspectHint); ?></span>
                                        <?php endif; ?>
                                    </td>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <p class="bot-updated">
        <?php
        // An absolute time stays correct on a cached page; "5 minutes ago" would not.
        printf(
            /* translators: %s: time the odds were fetched. */
            esc_html__('Odds updated at %s', 'best-odds-table'),
            '<time datetime="' . esc_attr($view['fetched_at']) . '">'
                . esc_html(wp_date(get_option('time_format'), $fetchedAt)) . '</time>'
        );
        ?>
    </p>

    <?php $gamblingNotice = SettingsPage::notice(); ?>
    <?php if ($gamblingNotice !== '') : ?>
        <p class="bot-responsible"><?php echo wp_kses($gamblingNotice, SettingsPage::NOTICE_HTML); ?></p>
    <?php endif; ?>
</div>
