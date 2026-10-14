# Best Odds Table

A WordPress plugin that compares odds from multiple bookmakers. Odds come from pluggable sources, are fetched on a schedule and cached, and are shown through a Gutenberg block with decimal, fractional and American formats.

**Full technical documentation:** [DOCS.md](DOCS.md) (architecture, design patterns, caching and rate limits, hooks, REST API, extending the plugin).

## Features

- **Admin page** (Settings → Best Odds Table): choose bookmakers, their order, names, affiliate links and logos; choose which markets editors may use; choose the odds source; see fetch status and run a fetch.
- **Gutenberg block** (Best Odds Table): pick an event and market, add, remove and reorder bookmakers, set the default format. Visitors switch between decimal, fractional and American odds; the best price is highlighted.
- **Sources:** demo fixture (default, always available) and live odds from The Odds API.
- **Performance:** visitors never trigger a fetch; two cache layers; works with full-page caching.

## Requirements

- PHP 8.1+
- WordPress 6.6+
- Composer

## Setup

```bash
cd wp-content/plugins/best-odds-table
composer install
```

Then activate **Best Odds Table** in wp-admin. Activation schedules the fetch jobs.

## Live odds (optional)

Out of the box the plugin uses demo data (`fixtures/odds.json`). For live odds it ships an adapter for [The Odds API](https://the-odds-api.com/), a licensed feed covering 9 of the 10 default bookmakers (bet365 is not offered):

1. Get a key at the-odds-api.com (the free plan is enough).
2. Add it to `wp-config.php`. It is a secret, so it is not stored in the database:
   ```php
   define('BEST_ODDS_TABLE_ODDS_API_KEY', 'your-key');
   ```
3. In **Settings → Best Odds Table**, choose **The Odds API** as the source.

One request returns every Premier League match with all bookmakers' match result prices, and the response is cached for 2 hours, about 12 requests a day, inside the free plan's 500 a month. On a paid plan, refresh more often:

```php
add_filter('best_odds_table_odds_api_cache_ttl', fn () => 5 * MINUTE_IN_SECONDS);
add_filter('best_odds_table_odds_api_sports', fn () => [
    'soccer_epl' => 'Premier League',
    'soccer_spain_la_liga' => 'La Liga',
]);
```

The table always shows when the source last updated the odds, not when the plugin last asked, so cached odds never look fresher than they are. Scraping Oddschecker was considered and rejected: it blocks automated requests (HTTP 403) and markup changes break scrapers without warning.

## Development

```bash
composer test   # PHPUnit
composer lint   # PHPCS, PSR-12
```

The block's built files in `build/` are committed, so the plugin works straight after cloning. After changing anything in `blocks/`, rebuild and commit `build/` with it:

```bash
npm install
npm run build      # or: npm start (rebuild on change)
npm run lint:js
npm run lint:css
```

## How it works

```
Post saved with odds blocks
        │  BlockScanner reads block attributes
        ▼
   WatchList  ── event/market pairs that content actually shows
        │
        ▼  WP-Cron, every 5 minutes
   OddsFetcher ── only due pairs (RefreshPolicy), batch limit, delay, lock, backoff
        │  OddsSourceInterface::fetchEvent()
        ▼
 OddsRepository ── hot cache (transients / object cache) + last-good snapshot (option)
        │
        ▼
 REST /odds + block render ── OddsTablePresenter (same view model for both)
```

### Why fetching scales

The plugin never fetches "all odds". It fetches only the event/market pairs that published content displays:

- **Unit of work is an event/market pair.** On a comparison site one event page lists every bookmaker, so adding bookmakers does not add requests.
- **Demand driven.** Saving a post records its blocks' pairs in the watch list; deleting the post removes them. A pair used by several posts is fetched once.
- **Refresh tiers.** Events within 3 hours of kick-off (or in play) refresh every 5 minutes, events within 24 hours every 15 minutes, later events hourly, finished events never.
- **Gentle on the source.** At most 20 requests per run with a delay between them, a lock against overlapping runs, and a full backoff when the source answers with a rate limit.
- **Visitors never trigger a fetch.** Pages and the public endpoint read from cache only. If the hot cache is empty, the last successful snapshot is served and flagged as stale.

## Structure

```
best-odds-table.php        Thin bootstrap: guard, autoload, Plugin::boot(), activation hooks
src/
  Plugin.php               Composition root, wires services into hooks
  Domain/                  Odds, OddsFormat, Bookmaker, Event, Market, Price
  Conversion/              OddsConverter (fractional / decimal / American)
  Source/                  OddsSourceInterface, SourceRegistry, EventFactory, FixtureSource
  Watch/                   WatchedMarket, WatchList, BlockScanner
  Fetch/                   OddsFetcher, RefreshPolicy, FetchReport
  Repository/              OddsRepository, CachedMarket, BookmakerRepository
  Storage/                 KeyValueStore, CacheInterface + WordPress implementations
  Presentation/            OddsTablePresenter
  Rest/                    OddsController
config/bookmakers.php      Default bookmaker catalogue
fixtures/odds.json         Demo data (generated, not real prices)
tests/                     PHPUnit tests (no WordPress needed)
```

## REST API

Namespace `best-odds-table/v1`.

| Route | Access | Purpose |
|---|---|---|
| `GET /odds?event=&market=&bookmakers[]=&format=` | public | Odds table for one event/market. Reads cache only. |
| `GET /events` | `edit_posts` | Available events and markets for the block's picker. |
| `GET /bookmakers` | `edit_posts` | Enabled bookmakers for the block's inspector. |

## Hooks

| Hook | Type | Use |
|---|---|---|
| `best_odds_table_register_sources` | action | Register extra sources on the `SourceRegistry`. |
| `best_odds_table_active_source` | filter | Choose the source id (default `fixture`). |
| `best_odds_table_refresh_policy` | filter | Replace the `RefreshPolicy` (custom intervals). |
| `best_odds_table_fetch_batch_size` | filter | Requests per run (default 20). |
| `best_odds_table_fetch_delay_us` | filter | Delay between requests in microseconds (default 250000). |
| `best_odds_table_fetched` | action | After a pair is fetched, e.g. to purge a page cache. |
| `best_odds_table_fetch_failed` | action | A fetch failed; receives the pair and the exception. |
| `best_odds_table_market_missing` | action | The source no longer lists a watched pair. |
| `best_odds_table_fetch_completed` | action | After each run; receives a `FetchReport`. |

## Key decisions

- **Odds are stored as decimal.** It is the only format that compares and sorts without loss. Fractional and American are presentation formats.
- **Sources sit behind an interface.** Scraping, a licensed API and local fixtures are interchangeable.
- **Cache holds arrays, not objects.** A plugin update can never break unserialising old entries.
- **The demo runs on fixture data** so it stays stable if a scraped site blocks requests or changes its markup.
- **Options instead of a custom table.** The watch list and snapshots are small and keyed. A custom table becomes worth it once a site watches thousands of pairs or needs price history.

## Extending: adding a source

```php
add_action('best_odds_table_register_sources', function ($sources) {
    $sources->register('my-api', fn () => new MyApiSource(/* ... */));
});

add_filter('best_odds_table_active_source', fn () => 'my-api');
```

`MyApiSource` implements `BestOddsTable\Source\OddsSourceInterface` and returns events in the normalised array shape documented in `EventFactory`. Throw `SourceException::rateLimited($id, $seconds)` on a 429 and the fetcher backs off.
