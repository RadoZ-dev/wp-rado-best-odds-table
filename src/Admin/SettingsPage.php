<?php

declare(strict_types=1);

namespace BestOddsTable\Admin;

use BestOddsTable\Fetch\FetchReport;
use BestOddsTable\Fetch\OddsFetcher;
use BestOddsTable\Repository\BookmakerRepository;
use BestOddsTable\Repository\MarketRepository;
use BestOddsTable\Source\SourceRegistry;
use BestOddsTable\Storage\KeyValueStore;
use BestOddsTable\Watch\WatchList;
use Closure;

/**
 * Settings > Best Odds Table.
 *
 * Built on the Settings API, so WordPress handles the nonce, the capability
 * check and saving through options.php. This class only registers the
 * settings, sanitizes them and renders the view.
 *
 * Also shows fetch status and offers a "run fetch now" action, so an admin
 * can see the plugin working without reading logs.
 */
final class SettingsPage
{
    public const PAGE = 'best-odds-table';
    public const GROUP = 'best_odds_table';
    public const CAPABILITY = 'manage_options';

    public const OPTION_BOOKMAKERS = 'best_odds_table_' . BookmakerRepository::OPTION;
    public const OPTION_MARKETS = 'best_odds_table_' . MarketRepository::OPTION;
    public const OPTION_SOURCE = 'best_odds_table_source';
    public const LAST_RUN_KEY = 'last_run';

    private const FETCH_ACTION = 'best_odds_table_fetch_now';

    /**
     * @param Closure(): OddsFetcher                $fetcher      Lazy, because sources are registered on plugins_loaded.
     * @param (Closure(): array<string, string>)|null $marketLabels Lazy translated market names, because
     *                                                              translations load after this page is built.
     */
    public function __construct(
        private readonly SettingsSanitizer $sanitizer,
        private readonly BookmakerRepository $bookmakers,
        private readonly MarketRepository $markets,
        private readonly SourceRegistry $sources,
        private readonly WatchList $watchList,
        private readonly KeyValueStore $store,
        private readonly Closure $fetcher,
        private readonly string $defaultSource,
        private readonly string $pluginBasename,
        private readonly string $viewsPath,
        private readonly string $assetsUrl,
        private readonly string $version,
        private readonly ?Closure $marketLabels = null,
    ) {
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'addPage']);
        add_action('admin_init', [$this, 'registerSettings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
        add_action('admin_post_' . self::FETCH_ACTION, [$this, 'handleFetchNow']);
        add_filter('plugin_action_links_' . $this->pluginBasename, [$this, 'addSettingsLink']);
    }

    /**
     * @internal Hooked to admin_menu.
     */
    public function addPage(): void
    {
        add_options_page(
            __('Best Odds Table', 'best-odds-table'),
            __('Best Odds Table', 'best-odds-table'),
            self::CAPABILITY,
            self::PAGE,
            [$this, 'render']
        );
    }

    /**
     * @internal Hooked to admin_init.
     */
    public function registerSettings(): void
    {
        register_setting(self::GROUP, self::OPTION_BOOKMAKERS, [
            'type' => 'array',
            'sanitize_callback' => [$this, 'sanitizeBookmakers'],
            'default' => [],
        ]);

        register_setting(self::GROUP, self::OPTION_MARKETS, [
            'type' => 'array',
            'sanitize_callback' => [$this, 'sanitizeMarkets'],
            'default' => [],
        ]);

        register_setting(self::GROUP, self::OPTION_SOURCE, [
            'type' => 'string',
            'sanitize_callback' => [$this, 'sanitizeSource'],
            'default' => $this->defaultSource,
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     *
     * @internal Settings API callback.
     */
    public function sanitizeBookmakers(mixed $input): array
    {
        [$rows, $warnings] = $this->sanitizer->bookmakers($input);

        foreach ($warnings as $i => $warning) {
            $this->warn(self::OPTION_BOOKMAKERS, 'bookmaker_' . $i, $this->message($warning));
        }

        $previousLogos = [];
        foreach ($this->bookmakers->all() as $existing) {
            $previousLogos[$existing['bookmaker']->id] = $existing['bookmaker']->logoUrl;
        }

        // WordPress-level URL normalisation on top of the pure validation.
        foreach ($rows as &$row) {
            $row['url'] = esc_url_raw($row['url'], ['http', 'https']);
            $row['logo'] = $row['logo'] === null ? null : esc_url_raw($row['logo'], ['http', 'https']);

            // Only changed logos are checked, so saving never sends a request per bookmaker.
            if ($row['logo'] !== null && $row['logo'] !== ($previousLogos[$row['id']] ?? null)) {
                $problem = $this->logoProblem($row['logo']);
                if ($problem !== null) {
                    $this->warn(self::OPTION_BOOKMAKERS, 'logo_' . $row['id'], sprintf(
                        /* translators: 1: bookmaker name, 2: reason, e.g. "the address is a web page, not an image". */
                        __('The logo for %1$s was removed: %2$s. Choose the image from the Media Library instead.', 'best-odds-table'),
                        $row['name'],
                        $this->logoReason($problem)
                    ));
                    $row['logo'] = null;
                }
            }
        }

        return $rows;
    }

    /**
     * Asks the logo's server what the address returns.
     *
     * wp_safe_remote_head() refuses private and loopback addresses (other than
     * this site's own host), so the check cannot be used to probe the network.
     */
    private function logoProblem(string $url): ?array
    {
        $response = wp_safe_remote_head($url, ['timeout' => 5, 'redirection' => 3]);

        if (is_wp_error($response)) {
            return null; // Unreachable right now: keep the address rather than guess.
        }

        $contentType = wp_remote_retrieve_header($response, 'content-type');

        return $this->sanitizer->logoProblem(
            (int) wp_remote_retrieve_response_code($response),
            is_array($contentType) ? (string) reset($contentType) : (string) $contentType
        );
    }

    /**
     * @return list<string>
     *
     * @internal Settings API callback.
     */
    public function sanitizeMarkets(mixed $input): array
    {
        [$keys, $warnings] = $this->sanitizer->markets($input);

        foreach ($warnings as $i => $warning) {
            $this->warn(self::OPTION_MARKETS, 'market_' . $i, $this->message($warning));
        }

        return $keys;
    }

    /**
     * WordPress prints settings messages without escaping them, so every
     * message is escaped here. Some contain admin input or a remote server's
     * response.
     */
    private function warn(string $setting, string $code, string $message): void
    {
        add_settings_error($setting, $code, esc_html($message), 'warning');
    }

    /**
     * Translated text for a sanitizer warning code.
     *
     * @param array{code: string, name?: string} $warning
     */
    private function message(array $warning): string
    {
        $name = $warning['name'] ?? '';

        return match ($warning['code']) {
            SettingsSanitizer::INVALID_LINK => sprintf(
                /* translators: %s: bookmaker name. */
                __('The link for %s was not a valid http(s) address and was reset to the default.', 'best-odds-table'),
                $name
            ),
            SettingsSanitizer::INVALID_LOGO => sprintf(
                /* translators: %s: bookmaker name. */
                __('The logo for %s was not a valid http(s) address and was removed.', 'best-odds-table'),
                $name
            ),
            SettingsSanitizer::NO_MARKETS => __('At least one market must stay enabled, so all markets were kept.', 'best-odds-table'),
            default => __('A setting could not be saved as entered.', 'best-odds-table'),
        };
    }

    /**
     * Translated reason for a logo check result.
     *
     * @param array{code: string, status?: int, type?: string} $problem
     */
    private function logoReason(array $problem): string
    {
        return match ($problem['code']) {
            SettingsSanitizer::LOGO_WEB_PAGE => __('the address is a web page, not an image', 'best-odds-table'),
            /* translators: %d: HTTP status code, e.g. 404. */
            SettingsSanitizer::LOGO_HTTP_ERROR => sprintf(__('the address returned HTTP %d', 'best-odds-table'), $problem['status'] ?? 0),
            /* translators: %s: content type the address returned, e.g. application/pdf. */
            default => sprintf(__('the address returned %s, not an image', 'best-odds-table'), $problem['type'] ?? ''),
        };
    }

    /**
     * @internal Settings API callback.
     */
    public function sanitizeSource(mixed $input): string
    {
        return $this->sanitizer->source($input, $this->sources->ids(), $this->defaultSource);
    }

    /**
     * @internal Hooked to admin_enqueue_scripts.
     */
    public function enqueueAssets(string $hookSuffix): void
    {
        if ($hookSuffix !== 'settings_page_' . self::PAGE) {
            return; // Never load assets on other admin screens.
        }

        wp_enqueue_media(); // The logo fields' "Choose" button opens the Media Library.
        wp_enqueue_style('best-odds-table-admin', $this->assetsUrl . 'admin.css', [], $this->version);
        wp_enqueue_script(
            'best-odds-table-admin',
            $this->assetsUrl . 'admin.js',
            ['jquery', 'jquery-ui-sortable'],
            $this->version,
            true
        );
    }

    /**
     * @internal Menu page callback.
     */
    public function render(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            return;
        }

        $sources = [];
        foreach ($this->sources->ids() as $id) {
            $sources[$id] = $this->sources->get($id)->label();
        }

        $view = [
            'bookmakers' => $this->bookmakers->all(),
            // Translated names where the plugin has them.
            'markets' => array_replace(
                $this->markets->all(),
                array_intersect_key($this->marketLabels ? ($this->marketLabels)() : [], $this->markets->all())
            ),
            'enabledMarkets' => array_keys($this->markets->enabled()),
            'sources' => $sources,
            'activeSource' => (string) get_option(self::OPTION_SOURCE, $this->defaultSource),
            'watchedCount' => count($this->watchList->all()),
            'lastRun' => $this->store->get(self::LAST_RUN_KEY),
            'nextRun' => wp_next_scheduled('best_odds_table_fetch'),
            'fetchAction' => self::FETCH_ACTION,
            // Display only: the value just picks a message from a fixed list.
            'notice' => isset($_GET['bot_fetch']) ? sanitize_key(wp_unslash($_GET['bot_fetch'])) : '',
            'fields' => [
                'bookmakers' => self::OPTION_BOOKMAKERS,
                'markets' => self::OPTION_MARKETS,
                'source' => self::OPTION_SOURCE,
            ],
            'group' => self::GROUP,
        ];

        require $this->viewsPath . 'admin-settings.php';
    }

    /**
     * Runs the scheduled fetch immediately. Respects the same lock, batch
     * limit and backoff as cron, so it cannot be used to hammer a source.
     *
     * @internal Hooked to admin_post_{action}.
     */
    public function handleFetchNow(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You are not allowed to do this.', 'best-odds-table'), 403);
        }

        check_admin_referer(self::FETCH_ACTION);

        $report = ($this->fetcher)()->run(time());
        $this->recordRun($report, time());

        wp_safe_redirect(add_query_arg(
            ['page' => self::PAGE, 'bot_fetch' => $report->status],
            admin_url('options-general.php')
        ));
        exit;
    }

    /**
     * Stores the outcome of a run for the status box. Called by cron too.
     */
    public function recordRun(FetchReport $report, int $at): void
    {
        $this->store->set(self::LAST_RUN_KEY, $report->toArray($at));
    }

    /**
     * @param array<int|string, string> $links
     *
     * @return array<int|string, string>
     *
     * @internal Hooked to plugin_action_links_{basename}.
     */
    public function addSettingsLink(array $links): array
    {
        $url = admin_url('options-general.php?page=' . self::PAGE);
        array_unshift($links, '<a href="' . esc_url($url) . '">' . esc_html__('Settings', 'best-odds-table') . '</a>');

        return $links;
    }
}
