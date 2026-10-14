<?php

declare(strict_types=1);

namespace BestOddsTable;

use BestOddsTable\Conversion\OddsConverter;
use BestOddsTable\Domain\OddsFormat;
use BestOddsTable\Fetch\OddsFetcher;
use BestOddsTable\Fetch\RefreshPolicy;
use BestOddsTable\Presentation\OddsTablePresenter;
use BestOddsTable\Repository\BookmakerRepository;
use BestOddsTable\Repository\OddsRepository;
use BestOddsTable\Rest\OddsController;
use BestOddsTable\Source\EventFactory;
use BestOddsTable\Source\FixtureSource;
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
    public const VERSION = '0.2.0';
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

    public function watchList(): WatchList
    {
        return $this->watchList;
    }

    public function presenter(): OddsTablePresenter
    {
        return new OddsTablePresenter($this->converter);
    }

    /**
     * The source the fetcher uses. Defaults to the fixture source so the
     * plugin works out of the box; switch with the filter or (later) the
     * settings page.
     */
    public function activeSource(): OddsSourceInterface
    {
        $id = (string) apply_filters('best_odds_table_active_source', FixtureSource::ID);

        return $this->sources->get($id);
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

        // Day 2: admin settings page and the Gutenberg block.
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
        (new OddsController($this->odds, $this->bookmakers, $this->presenter(), $this->fetcher()))->register();
    }
}
