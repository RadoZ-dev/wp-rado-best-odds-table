<?php

declare(strict_types=1);

namespace BestOddsTable;

use BestOddsTable\Admin\SettingsPage;
use BestOddsTable\Admin\SettingsSanitizer;
use BestOddsTable\Block\OddsTableBlock;
use BestOddsTable\Conversion\OddsConverter;
use BestOddsTable\Domain\OddsFormat;
use BestOddsTable\Fetch\OddsFetcher;
use BestOddsTable\Fetch\RefreshPolicy;
use BestOddsTable\Http\WpHttpClient;
use BestOddsTable\Presentation\OddsTablePresenter;
use BestOddsTable\Repository\BookmakerRepository;
use BestOddsTable\Repository\MarketRepository;
use BestOddsTable\Repository\OddsRepository;
use BestOddsTable\Rest\OddsController;
use BestOddsTable\Source\EventFactory;
use BestOddsTable\Source\FixtureSource;
use BestOddsTable\Source\OddsApiSource;
use BestOddsTable\Source\OddsSourceInterface;
use BestOddsTable\Source\SourceException;
use BestOddsTable\Source\SourceRegistry;
use BestOddsTable\Storage\TransientCache;
use BestOddsTable\Storage\WpOptionStore;
use BestOddsTable\Watch\BlockScanner;
use BestOddsTable\Watch\WatchedMarket;
use BestOddsTable\Watch\WatchList;
use WP_Post;

/**
 * Composition root of the plugin.
 *
 * Builds the shared services and wires them into WordPress hooks. This is
 * the only class that knows how everything fits together; the services are
 * plain objects with constructor dependencies, so they are testable without
 * WordPress.
 *
 * A single instance is kept because some setups include the bootstrap more
 * than once; boot() is idempotent.
 */
final class Plugin
{
    public const VERSION = '0.4.0';
    public const SLUG = 'best-odds-table';

    public const CRON_FETCH = 'best_odds_table_fetch';
    public const CRON_INDEX = 'best_odds_table_refresh_index';
    public const CRON_SCHEDULE = 'best_odds_table_five_minutes';

    private static ?self $instance = null;

    private readonly OddsConverter $converter;
    private readonly SourceRegistry $sources;
    private readonly WpOptionStore $store;
    private readonly TransientCache $cache;
    private readonly WatchList $watchList;
    private readonly OddsRepository $odds;
    private readonly BookmakerRepository $bookmakers;
    private readonly MarketRepository $markets;
    private ?OddsFetcher $fetcher = null;

    private function __construct(private readonly string $pluginFile)
    {
        $this->converter = new OddsConverter();
        $this->sources = new SourceRegistry();
        $this->store = new WpOptionStore();
        $this->cache = new TransientCache();
        $this->watchList = new WatchList($this->store);
        $this->odds = new OddsRepository(
            $this->cache,
            $this->store,
            new EventFactory($this->converter, OddsFormat::Decimal)
        );
        $this->bookmakers = new BookmakerRepository(
            $this->store,
            require $this->path('config/bookmakers.php')
        );
        $this->markets = new MarketRepository(
            $this->store,
            require $this->path('config/markets.php')
        );
    }

    public static function boot(string $pluginFile): self
    {
        if (self::$instance === null) {
            self::$instance = new self($pluginFile);
            self::$instance->registerHooks();
        }

        return self::$instance;
    }

    public static function instance(): self
    {
        if (self::$instance === null) {
            throw new \LogicException('Plugin has not been booted yet.');
        }

        return self::$instance;
    }

    public static function activate(): void
    {
        self::instance()->scheduleCron();
    }

    public static function deactivate(): void
    {
        wp_clear_scheduled_hook(self::CRON_FETCH);
        wp_clear_scheduled_hook(self::CRON_INDEX);
    }

    public function converter(): OddsConverter
    {
        return $this->converter;
    }

    public function sources(): SourceRegistry
    {
        return $this->sources;
    }

    public function odds(): OddsRepository
    {
        return $this->odds;
    }

    public function bookmakers(): BookmakerRepository
    {
        return $this->bookmakers;
    }

    public function markets(): MarketRepository
    {
        return $this->markets;
    }

    public function watchList(): WatchList
    {
        return $this->watchList;
    }

    public function presenter(): OddsTablePresenter
    {
        return new OddsTablePresenter($this->converter);
    }

    /**
     * The source the fetcher uses: the one chosen on the settings page,
     * falling back to the fixture source. The filter can still override it.
     */
    public function activeSource(): OddsSourceInterface
    {
        $saved = (string) get_option(SettingsPage::OPTION_SOURCE, FixtureSource::ID);
        $id = (string) apply_filters('best_odds_table_active_source', $saved);

        return $this->sources->get($this->sources->has($id) ? $id : FixtureSource::ID);
    }

    /**
     * Built lazily: sources are only registered on plugins_loaded.
     */
    public function fetcher(): OddsFetcher
    {
        return $this->fetcher ??= new OddsFetcher(
            source: $this->activeSource(),
            watchList: $this->watchList,
            repository: $this->odds,
            policy: apply_filters('best_odds_table_refresh_policy', new RefreshPolicy()),
            cache: $this->cache,
            batchSize: (int) apply_filters('best_odds_table_fetch_batch_size', 20),
            delayMicroseconds: (int) apply_filters('best_odds_table_fetch_delay_us', 250_000),
            dispatch: 'do_action',
        );
    }

    public function path(string $relative = ''): string
    {
        return plugin_dir_path($this->pluginFile) . ltrim($relative, '/');
    }

    public function url(string $relative = ''): string
    {
        return plugin_dir_url($this->pluginFile) . ltrim($relative, '/');
    }

    private function registerHooks(): void
    {
        add_action('plugins_loaded', [$this, 'registerSources'], 5);

        // Scheduling.
        add_filter('cron_schedules', [$this, 'addCronSchedule']);
        add_action('init', [$this, 'scheduleCron']);
        add_action(self::CRON_FETCH, [$this, 'runFetch']);
        add_action(self::CRON_INDEX, [$this, 'runIndexRefresh']);

        // Watch list follows content.
        add_action('save_post', [$this, 'onSavePost'], 20, 2);
        add_action('trashed_post', [$this, 'onRemovePost']);
        add_action('deleted_post', [$this, 'onRemovePost']);

        // API.
        add_action('rest_api_init', [$this, 'registerRest']);

        // Admin.
        if (is_admin()) {
            $this->settingsPage()->register();
        }

        // Block.
        add_action('init', [$this, 'registerBlock']);
    }

    /**
     * Used by the block's render.php.
     */
    public function oddsTableBlock(): OddsTableBlock
    {
        return new OddsTableBlock($this->odds, $this->bookmakers, $this->markets, $this->presenter());
    }

    /**
     * Registers the block from its built metadata (build/odds-table/block.json),
     * which also registers its scripts, styles and render.php.
     *
     * @internal Hooked to init.
     */
    public function registerBlock(): void
    {
        $path = $this->path('build/odds-table');

        if (!is_readable($path . '/block.json')) {
            add_action('admin_notices', static function (): void {
                echo '<div class="notice notice-warning"><p>'
                    . esc_html__(
                        'Best Odds Table: the block is not built. Run "npm install && npm run build" in the plugin directory.',
                        'best-odds-table'
                    )
                    . '</p></div>';
            });

            return;
        }

        register_block_type($path);
    }

    private function settingsPage(): SettingsPage
    {
        return new SettingsPage(
            sanitizer: new SettingsSanitizer(
                require $this->path('config/bookmakers.php'),
                $this->markets->all()
            ),
            bookmakers: $this->bookmakers,
            markets: $this->markets,
            sources: $this->sources,
            watchList: $this->watchList,
            store: $this->store,
            fetcher: fn (): OddsFetcher => $this->fetcher(),
            defaultSource: FixtureSource::ID,
            pluginBasename: plugin_basename($this->pluginFile),
            viewsPath: $this->path('views/'),
            assetsUrl: $this->url('assets/'),
            version: self::VERSION,
        );
    }

    /**
     * @internal Hooked to plugins_loaded.
     */
    public function registerSources(): void
    {
        $factory = new EventFactory($this->converter);
        $fixturePath = $this->path('fixtures/odds.json');

        $this->sources->register(
            FixtureSource::ID,
            static fn (): OddsSourceInterface => new FixtureSource($fixturePath, $factory)
        );

        // Live source, offered only when a key is configured. The key lives in
        // wp-config.php, not the database: it is a secret, and options leak into
        // backups, exports and staging copies.
        $apiKey = defined('BEST_ODDS_TABLE_ODDS_API_KEY') ? (string) constant('BEST_ODDS_TABLE_ODDS_API_KEY') : '';
        if ($apiKey !== '') {
            $converter = $this->converter;
            $cache = $this->cache;
            $this->sources->register(
                OddsApiSource::ID,
                static fn (): OddsSourceInterface => new OddsApiSource(
                    http: new WpHttpClient(),
                    cache: $cache,
                    factory: new EventFactory($converter, OddsFormat::Decimal),
                    apiKey: $apiKey,
                    sports: (array) apply_filters('best_odds_table_odds_api_sports', ['soccer_epl' => 'Premier League']),
                    bookmakerMap: (array) apply_filters('best_odds_table_odds_api_bookmakers', OddsApiSource::BOOKMAKERS),
                    cacheTtl: (int) apply_filters('best_odds_table_odds_api_cache_ttl', 2 * HOUR_IN_SECONDS),
                )
            );
        }

        /**
         * Fires after built-in odds sources are registered.
         *
         * @param SourceRegistry $sources Register more with $sources->register().
         */
        do_action('best_odds_table_register_sources', $this->sources);
    }

    /**
     * @param array<string, array{interval: int, display: string}> $schedules
     *
     * @return array<string, array{interval: int, display: string}>
     *
     * @internal Hooked to cron_schedules.
     */
    public function addCronSchedule(array $schedules): array
    {
        $schedules[self::CRON_SCHEDULE] = [
            'interval' => 5 * MINUTE_IN_SECONDS,
            'display' => __('Every five minutes (Best Odds Table)', 'best-odds-table'),
        ];

        return $schedules;
    }

    /**
     * Idempotent, so it also repairs schedules lost on migrations or
     * deployments that skip the activation hook.
     */
    public function scheduleCron(): void
    {
        if (!wp_next_scheduled(self::CRON_FETCH)) {
            wp_schedule_event(time(), self::CRON_SCHEDULE, self::CRON_FETCH);
        }
        if (!wp_next_scheduled(self::CRON_INDEX)) {
            wp_schedule_event(time(), 'hourly', self::CRON_INDEX);
        }
    }

    /**
     * @internal Cron callback.
     */
    public function runFetch(): void
    {
        $report = $this->fetcher()->run(time());
        $this->store->set(SettingsPage::LAST_RUN_KEY, $report->toArray(time()));

        do_action('best_odds_table_fetch_completed', $report);
    }

    /**
     * @internal Cron callback.
     */
    public function runIndexRefresh(): void
    {
        try {
            $this->fetcher()->refreshIndex();
        } catch (SourceException $e) {
            do_action('best_odds_table_index_failed', $e);
        }
    }

    /**
     * Keeps the watch list in sync with the odds blocks in a post, and
     * queues an immediate fetch for pairs nobody watched before.
     *
     * Only published and scheduled posts are watched: drafts would let any
     * contributor add fetch load, and the editor preview has its own
     * on-demand fetch. Pairs the index says the source does not list are
     * dropped, because block attributes can be edited by hand.
     *
     * @internal Hooked to save_post.
     */
    public function onSavePost(int $postId, WP_Post $post): void
    {
        if (wp_is_post_revision($postId) || wp_is_post_autosave($postId)) {
            return;
        }

        // Also covers trash, and posts moved back to draft.
        if (!in_array($post->post_status, ['publish', 'future'], true)) {
            $this->watchList->removePost($postId);
            return;
        }

        $pairs = array_values(array_filter(
            (new BlockScanner())->pairs(parse_blocks($post->post_content)),
            // Null means no index yet: accept rather than drop real pairs.
            fn (WatchedMarket $pair): bool => $this->odds->isListed($pair) !== false
        ));
        $added = $this->watchList->syncPost($postId, $pairs);

        // A distinct argument is required: WordPress drops a single event that
        // duplicates the hook and args of one already due within 10 minutes,
        // which the recurring five-minute run always is.
        if ($added !== [] && !wp_next_scheduled(self::CRON_FETCH, ['immediate'])) {
            wp_schedule_single_event(time(), self::CRON_FETCH, ['immediate']);
        }
    }

    /**
     * @internal Hooked to trashed_post and deleted_post.
     */
    public function onRemovePost(int $postId): void
    {
        $this->watchList->removePost($postId);
    }

    /**
     * @internal Hooked to rest_api_init.
     */
    public function registerRest(): void
    {
        (new OddsController(
            $this->odds,
            $this->bookmakers,
            $this->markets,
            $this->presenter(),
            $this->fetcher()
        ))->register();
    }
}
