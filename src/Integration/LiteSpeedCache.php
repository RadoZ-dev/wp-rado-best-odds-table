<?php

declare(strict_types=1);

namespace BestOddsTable\Integration;

use BestOddsTable\Watch\WatchedMarket;

/**
 * LiteSpeed Cache integration: purge exactly the cached pages whose odds changed.
 *
 * Every page with an odds table is tagged with one tag per event/market it
 * shows; storing new odds for a pair purges that tag only. Pages also get a
 * shorter cache lifetime as a safety net, since odds go stale in hours, not
 * the default week.
 *
 * Talks to LiteSpeed only through its documented actions (litespeed_tag_add,
 * litespeed_purge, litespeed_control_set_ttl). Without LiteSpeed Cache nobody
 * listens to them, so this class does nothing and the plugin keeps no
 * dependency on it.
 */
final class LiteSpeedCache
{
    /** Tag on every page that shows an odds table. */
    public const TAG_ALL = 'bot';

    /** Settings whose change alters every table: bookmakers, links, logos, markets, notice. */
    private const SETTINGS = [
        'best_odds_table_bookmakers',
        'best_odds_table_markets',
        'best_odds_table_notice',
    ];

    /**
     * @param int $ttl Cache lifetime, in seconds, for pages that show odds.
     */
    public function __construct(private readonly int $ttl = 3600)
    {
    }

    public function register(): void
    {
        add_action('best_odds_table_rendered', [$this, 'tagPage'], 10, 2);
        add_action('best_odds_table_fetched', [$this, 'purgePair']);
        add_action('best_odds_table_source_changed', [$this, 'purgeAll']);

        foreach (self::SETTINGS as $option) {
            add_action('update_option_' . $option, [$this, 'purgeAll']);
            add_action('add_option_' . $option, [$this, 'purgeAll']);
        }
    }

    /**
     * Short and safe for any id: event ids come from external sources.
     */
    public static function tag(string $eventId, string $marketKey): string
    {
        return 'bot_' . substr(md5($eventId . '|' . $marketKey), 0, 12);
    }

    /**
     * @internal Hooked to best_odds_table_rendered.
     */
    public function tagPage(string $eventId, string $marketKey): void
    {
        do_action('litespeed_tag_add', [self::TAG_ALL, self::tag($eventId, $marketKey)]);

        // Read at render time, so a theme's functions.php can still change it.
        $ttl = (int) apply_filters('best_odds_table_litespeed_ttl', $this->ttl);
        do_action('litespeed_control_set_ttl', $ttl, 'Best Odds Table: odds go stale within hours');
    }

    /**
     * @internal Hooked to best_odds_table_fetched.
     */
    public function purgePair(WatchedMarket $pair): void
    {
        do_action('litespeed_purge', self::tag($pair->eventId, $pair->marketKey));
    }

    /**
     * @internal Hooked to best_odds_table_source_changed and settings updates.
     */
    public function purgeAll(): void
    {
        do_action('litespeed_purge', self::TAG_ALL);
    }
}
