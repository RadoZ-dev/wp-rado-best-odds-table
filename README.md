# Best Odds Table

A WordPress plugin that compares odds from multiple bookmakers. Odds come from pluggable sources, are fetched on a schedule and cached, and are shown through a Gutenberg block with decimal, fractional and American formats.

> Status: work in progress. Domain model, odds conversion, sources, watch list, scheduled fetching, caching and the REST API are in place; the admin page and the block follow.

## Requirements

- PHP 8.1+
- WordPress 6.5+
- Composer

## Setup

```bash
cd wp-content/plugins/best-odds-table
composer install
```

Then activate **Best Odds Table** in wp-admin. Activation schedules the fetch jobs.

## Development

```bash
composer test   # PHPUnit
composer lint   # PHPCS, PSR-12
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
