# Best Odds Table: technical documentation

This document explains how the plugin is built, how to use it, and how to extend it. For installation, see the [README](README.md).

**Contents**

1. [Overview](#1-overview)
2. [Architecture](#2-architecture)
3. [Design principles and patterns](#3-design-principles-and-patterns)
4. [Odds sources](#4-odds-sources)
5. [Fetching, rate limits and performance](#5-fetching-rate-limits-and-performance)
6. [Data accuracy](#6-data-accuracy)
7. [Odds conversion](#7-odds-conversion)
8. [Admin settings](#8-admin-settings)
9. [The Gutenberg block](#9-the-gutenberg-block)
10. [REST API](#10-rest-api)
11. [Hooks reference](#11-hooks-reference)
12. [Extending the plugin](#12-extending-the-plugin)
13. [Security](#13-security)
14. [Translations](#14-translations)
15. [Testing and code quality](#15-testing-and-code-quality)
16. [Deployment and uninstall](#16-deployment-and-uninstall)
17. [Known limitations and next steps](#17-known-limitations-and-next-steps)

---

## 1. Overview

Best Odds Table compares football odds from about ten bookmakers and shows them in a Gutenberg block.

- **Sources are pluggable.** Odds come from a demo fixture or live from a licensed API ([The Odds API](https://the-odds-api.com/)); a new source is one class.
- **Fetching is scheduled and demand driven.** Only the event/market pairs that published content shows are fetched, on WP-Cron, never during a page view.
- **Two cache layers.** A hot cache (transients or object cache) and a durable last-good snapshot, so a failing source never empties a page.
- **Admins control** which bookmakers appear, their order, names, affiliate links and logos (uploaded through the Media Library), which markets editors may use, which source is active, and the responsible gambling notice.
- **Editors** add the block, pick an event and market, and add, remove and reorder bookmakers per block.
- **Visitors** switch between decimal, fractional and American odds without a request; the best price per outcome is highlighted, and prices that look like data errors are flagged instead of being advertised as best.

Scope is deliberately tight: football, two markets (Match Result and Both Teams To Score). Depth and correctness were preferred over breadth.

---

## 2. Architecture

### Data flow

```
 Editor saves a post with odds blocks
        │  save_post → BlockScanner reads each block's eventId + marketKey
        ▼
   WatchList ─────── which event/market pairs published content shows
        │
        ▼  WP-Cron, every 5 minutes (plus an immediate run for new pairs)
   OddsFetcher ───── only due pairs (RefreshPolicy), batch of 20, delay, atomic lock, backoff
        │  OddsSourceInterface::fetchEvent()
        ▼
   FixtureSource │ OddsApiSource │ your source
        │  normalised arrays → EventFactory → Event / Market / Price
        ▼
   OddsRepository ── hot cache (TTL) + last-good snapshot (non-autoloaded option)
        │
        ├──▶ block render.php ──┐
        └──▶ REST GET /odds ────┴─▶ OddsTablePresenter (one view model for both)
```

Reading and writing are separate: **only cron writes odds; page views and the public REST endpoint only read.**

### Directory structure

```
best-odds-table.php         Thin bootstrap: ABSPATH guard, autoloader, Plugin::boot()
src/
  Plugin.php                Composition root: builds services, registers every hook
  Domain/                   Odds, OddsFormat, Bookmaker, Event, Market, Price
  Conversion/               OddsConverter (fractional / decimal / American)
  Source/                   OddsSourceInterface, SourceRegistry, EventFactory,
                            FixtureSource, OddsApiSource, SourceException
  Http/                     HttpClient, HttpResponse, WpHttpClient
  Watch/                    WatchedMarket, WatchList, BlockScanner
  Fetch/                    OddsFetcher, RefreshPolicy, FetchReport
  Repository/               OddsRepository, CachedMarket, BookmakerRepository, MarketRepository
  Storage/                  KeyValueStore, CacheInterface, Lock, WpOptionStore, TransientCache,
                            WpOptionLock (atomic), CacheLock (tests)
  Presentation/             OddsTablePresenter
  Block/                    OddsTableBlock (attributes → view model)
  Rest/                     OddsController
  Admin/                    SettingsPage, SettingsSanitizer
  Integration/              LiteSpeedCache (tag-based page cache purging)
blocks/odds-table/          Block source: block.json, edit.js, render.php, view.js, SCSS
build/odds-table/           Compiled block (committed, so the plugin works after cloning)
views/admin-settings.php    Settings page template
assets/                     Settings page CSS and JS
config/                     Default bookmakers and the market catalogue
fixtures/odds.json          Demo odds
tests/                      PHPUnit tests and a recorded API response
uninstall.php               Removes all plugin data when the plugin is deleted
```

### Where WordPress is used

WordPress functions are confined to `Plugin`, `Rest/`, `Admin/SettingsPage`, `Storage/Wp*`, `Http/WpHttpClient`, the block's `render.php`, the settings view and `uninstall.php`. Everything else (domain, conversion, sources, watch list, fetcher, repositories, presenter) is plain PHP behind interfaces, which is why the whole pipeline is unit tested without loading WordPress.

### Request lifecycles

| Situation | What happens | Calls the source? |
|---|---|---|
| Visitor opens a page | `render.php` → `OddsTableBlock::viewModel()` → `OddsRepository::find()` → presenter | No |
| Visitor switches format | `view.js` changes one attribute; CSS shows the other format | No request at all |
| Cron run (every 5 min) | `OddsFetcher::run()` fetches due pairs, saves both cache layers | Yes, at most 20 pairs |
| Editor saves a post | `BlockScanner` updates the watch list; new pairs queue an immediate fetch | Via cron, seconds later |
| Editor previews a new pair | Editor calls `GET /odds`; `fetchOnDemand()` fetches it once if the source lists it | Once, guarded |
| Admin switches source | Previous source's odds, index, fetch state and backoff are dropped; index and fetch run immediately | Via cron |

---

## 3. Design principles and patterns

### Patterns used

| Pattern | Where | Why |
|---|---|---|
| **Singleton** | `Plugin::boot()` / `Plugin::instance()` | One composition root even if the bootstrap is included twice; `render.php` reaches services through it. Used only here: every other class gets its dependencies through the constructor. |
| **Dependency injection** | Every service; wired in `Plugin` | Classes ask for interfaces (`OddsSourceInterface`, `CacheInterface`, `KeyValueStore`, `Lock`, `HttpClient`), so tests pass fakes and providers are swappable. Even translated labels are injected, so plain-PHP classes never call WordPress. |
| **Factory** | `EventFactory` | One place that validates untrusted source data and builds domain objects. Sources only produce arrays. |
| **Registry + lazy factory** | `SourceRegistry` | Sources register a closure; the object is built only when used, so registering many providers costs nothing on normal requests. |
| **Strategy** | `OddsSourceInterface` implementations; `RefreshPolicy` (replaceable by filter); `Lock` (`WpOptionLock` in WordPress, `CacheLock` in tests) | Where odds come from, how often they refresh and how runs are serialised vary independently of the fetcher. |
| **Adapter** | `OddsApiSource`; `WpOptionStore`, `TransientCache`, `WpOptionLock`, `WpHttpClient` | External formats and WordPress APIs are adapted to the plugin's own interfaces. |
| **Repository** | `OddsRepository`, `BookmakerRepository`, `MarketRepository` | Storage details (two cache layers, option shapes, defaults) stay out of business logic. |
| **Observer** | WordPress actions; `OddsFetcher` publishes events through an injected `dispatch` callable (`do_action` in production) | Other code reacts to fetches (purge a page cache, log failures) without the fetcher knowing about it. |
| **Presenter / view model** | `OddsTablePresenter` | REST and the block render from one view model, so they cannot disagree. |
| **Value objects** | `Odds`, `Price`, `WatchedMarket`, `FetchReport`, `HttpResponse` | Immutable (`readonly`), validated on construction. |

### OOP principles

- **Encapsulation.** Properties are `private readonly` unless they are part of a value object's public, immutable state. `Market` exposes queries (`bestPrice()`, `priceFor()`), not its price list.
- **Polymorphism.** The fetcher works with any `OddsSourceInterface`; repositories work with any `CacheInterface` / `KeyValueStore`; the API source works with any `HttpClient`. Production and tests use different implementations of the same contracts.
- **Composition over inheritance, deliberately.** Classes are `final` and share behaviour through interfaces and composition. Deep inheritance would couple, for example, a scraper and an API client through a base class they do not truly share; interfaces keep each source independent. Inheritance is used where it is the natural tool: `SourceException extends RuntimeException`, `OddsFormat` is a backed enum.
- **Single responsibility.** One class decides *when* to fetch (`RefreshPolicy`), one *what* is watched (`WatchList`), one *how* to fetch safely (`OddsFetcher`), one *where* data lives (`OddsRepository`), one *how it looks* (`OddsTablePresenter`).

### Conventions

PSR-12, `declare(strict_types=1)` everywhere, PHP 8.1 features (readonly properties, enums, constructor promotion), constructor injection, cached data stored as normalised arrays (never serialised objects, so a plugin update can never break reading old cache entries).

---

## 4. Odds sources

| Source | Id | Bookmakers | Markets | When to use |
|---|---|---|---|---|
| `FixtureSource` | `fixture` | All 10 | Match Result, BTTS | Default. Demo, development, tests. |
| `OddsApiSource` | `the-odds-api` | 9 of 10 (bet365 is not offered) | Match Result | Live odds. Needs an API key. |

### Fixture source

Reads [fixtures/odds.json](fixtures/odds.json). Kick-off times roll forward in whole days so the earliest match is always tomorrow: the demo never shows only finished matches, times keep their time of day, and event ids never change, so blocks keep working. Without a clock (as in tests) the file's dates are used as written.

### The Odds API source

A licensed odds feed. One request per sport returns every upcoming match with all UK bookmakers' match result prices, so **cost does not grow with the number of events or bookmakers**.

- **Quota.** The response is cached for 2 hours (filterable). However often the fetcher, the hourly index refresh or editors ask, the API is called at most 12 times a day per sport, about 370 credits a month, inside the free plan's 500.
- **Mapping.** API bookmaker keys map to plugin ids (`williamhill` → `william-hill`, `betfair_sb_uk` → `betfair`, …; filterable). Outcomes are matched **by team name**, because the API lists them in no fixed order.
- **Errors.** HTTP 429 becomes `SourceException::rateLimited()` with `Retry-After`; an exhausted monthly quota (401 `OUT_OF_USAGE_CREDITS`) backs off for 6 hours; other failures are plain `SourceException`s. Messages never include the request URL, because it carries the API key.
- **Key.** Read from the `BEST_ODDS_TABLE_ODDS_API_KEY` constant in `wp-config.php`, not from the database, because options leak into backups, exports and staging copies. Without the constant, the source is not registered at all.
- **BTTS** is a per-event "additional market" on this API (one request per event), so it is not enabled by default.

### Why not scraping Oddschecker

The brief suggests scraping a comparison site such as Oddschecker. This was evaluated and rejected:

1. **It is blocked.** A plain request to oddschecker.com returns **HTTP 403** (bot protection). Getting past it would need a headless browser and rotating proxies, which means evading the site's protection.
2. **Terms of use.** Comparison sites sell this data and prohibit automated collection.
3. **Fragility.** Any markup change silently breaks a parser, which hurts data accuracy, one of the evaluation criteria.

The architecture still supports scraping: a scraper is just another `OddsSourceInterface`. It would fetch HTML through `HttpClient`, parse it (for example with `DOMDocument` and `DOMXPath`) into the normalised array shape, and throw `SourceException::rateLimited()` on 429 or a challenge page. Everything else (scheduling, batching, backoff, caching, rendering) already applies unchanged. See [Adding a source](#adding-a-source).

### Switching sources

Changing the source in Settings drops everything the previous source produced: cached odds for watched pairs (including last-good snapshots), the event index, fetch state and any rate-limit backoff. An index refresh and a fetch are queued immediately. Without this, pages would keep showing the previous source's prices indefinitely, flagged as stale. Which posts show which pairs is kept.

---

## 5. Fetching, rate limits and performance

### Demand-driven fetching

The plugin never fetches "all odds". The unit of work is an **event/market pair** that published content shows:

- Saving a post records its blocks' pairs in the `WatchList`; trashing, deleting or unpublishing removes them. A pair used by several posts is fetched once.
- Only `publish` and `future` posts are watched, so drafts cannot add fetch load.
- Pairs the event index says the source does not list are dropped, because block attributes can be edited by hand.

Load therefore grows with the site's content, not with the number of matches in the world.

### Refresh tiers

`RefreshPolicy` sets how often a pair is refreshed, based on time to kick-off:

| Event | Refresh every |
|---|---|
| Within 3 hours of kick-off, or in play | 5 minutes |
| Within 24 hours | 15 minutes |
| Later | 1 hour |
| Finished (3 hours after kick-off) | Never |
| Never found by the source | 1 hour |

The hot cache lives for two refresh cycles, so one failed fetch does not push readers onto the stale snapshot.

### Protecting the source

| Protection | Detail |
|---|---|
| Due pairs only | Never-fetched and most overdue first, so a capped batch always serves the oldest data. |
| Batch limit | 20 requests per run (filter `best_odds_table_fetch_batch_size`); the rest wait for the next run. |
| Delay | 250 ms between requests (filter `best_odds_table_fetch_delay_us`). |
| Atomic lock | Overlapping cron runs (for example a server cron job and a visitor-triggered WP-Cron at the same moment) cannot both run. See [The fetch lock](#the-fetch-lock). |
| Backoff | On a rate limit the whole fetcher pauses for `Retry-After`, and the run stops immediately. |
| Guarded on-demand fetch | The editor preview can fetch a pair once, but only if the index lists it and never during a backoff. |
| Response cache | Sources such as `OddsApiSource` cache their own responses to cap quota use. |

### The fetch lock

A lock is only useful if taking it is atomic: "is it free? then take it" in two steps lets two runs that start together both proceed. [`WpOptionLock`](src/Storage/WpOptionLock.php) follows WordPress core's `WP_Upgrader::create_lock()`:

1. `INSERT IGNORE` a row in the options table. The `option_name` unique key guarantees exactly one process succeeds.
2. If the row exists, read when it expires (directly from the database, not the options cache).
3. If it has expired (a run crashed and never released it), take it over with a **conditional** `UPDATE … WHERE option_value = <old expiry>`, so of two processes that both see it expired, only one wins. Core uses a plain `update_option()` at this step.

`add_option()` is not enough: it checks whether the option exists and then inserts with `ON DUPLICATE KEY UPDATE`, so two processes that check at the same moment both "succeed". The lock expires after 120 seconds; a run of 20 requests with 250 ms between them takes well under that.

### Performance on the front end

- **Visitors never trigger a fetch.** Pages and `GET /odds` read cache only.
- **Two cache layers.** Transients use the persistent object cache when one exists (Redis, Memcached); the last-good snapshot is a non-autoloaded option, so odds are never loaded into memory on unrelated requests.
- **Full-page-cache friendly.** The block renders all three formats into each cell and the visitor's choice lives in the browser, so one cached page serves every visitor. The "Odds updated at 14:05" line is an absolute time, which stays correct on a cached page ("5 minutes ago" would not).
- **Page cache purging (LiteSpeed Cache).** A full-page cache would otherwise keep a page's odds for its whole lifetime (a week by default in LiteSpeed). [`LiteSpeedCache`](src/Integration/LiteSpeedCache.php) tags each page with one tag per event/market it shows and purges only that tag when new odds are stored; changing the source or the bookmaker, market or notice settings purges every page with an odds table; and those pages get a 1-hour lifetime as a safety net (filter `best_odds_table_litespeed_ttl`). It only calls LiteSpeed's documented actions (`litespeed_tag_add`, `litespeed_purge`, `litespeed_control_set_ttl`), so without LiteSpeed nothing happens. Other cache plugins can do the same through the `best_odds_table_rendered` and `best_odds_table_fetched` actions.
- **REST caching headers.** `GET /odds` sends `Cache-Control: public, max-age=60` (or `no-store` when serving a stale snapshot), so a CDN can absorb traffic.
- **Small front-end payload.** The only front-end JavaScript is a ~0.5 KB Interactivity API module, loaded only on pages that contain the block. Settings page assets load only on that screen.
- **No custom tables.** The watch list and snapshots are small and keyed; options are the simplest correct storage at this scale (see [limitations](#17-known-limitations-and-next-steps)).

---

## 6. Data accuracy

- **One canonical format.** Odds are stored as decimals with 6 decimal places, so fractions such as 10/11 survive the round trip exactly. Fractional and American are presentation formats.
- **Validation at the boundary.** `EventFactory` rejects events with missing fields, invalid ids or unparseable dates, and skips single malformed prices without dropping the market. Event ids and market keys must match strict patterns (`Event::ID_PATTERN`, `Market::KEY_PATTERN`) before they reach storage keys or request URLs.
- **Truthful timestamps.** Events carry the source's own update time, and the fetcher stores that, not "now". A source serving a cached response can never make old odds look fresh.
- **Stale is visible.** When the hot cache has expired and the last-good snapshot is served, the block shows "These odds could not be refreshed recently and may be out of date".
- **Best price among what is shown.** The highlighted best price is calculated across the bookmakers this block displays, not across all bookmakers; ties are all highlighted.
- **Suspect prices are never "best".** See below.
- **No mixing of sources.** Switching source clears the previous source's data (see [Switching sources](#switching-sources)).
- **Logos are checked.** When a logo changes, saving the settings asks its server what the address returns; a web page or an error is removed with a warning (see [Admin settings](#8-admin-settings)).

### Suspect price guard

A comparison table's job is to point at the best price, so one bad number from a feed (11.0 typed for 1.10) would send visitors to the wrong bookmaker with the plugin's endorsement. [`OddsTablePresenter`](src/Presentation/OddsTablePresenter.php) therefore checks every price before choosing the best:

- For each outcome, prices from the bookmakers shown are compared as **implied probabilities** (1 / decimal odds) against their **median**, which one bad price cannot drag.
- A price more than **50%** away from the median is *suspect* (filter `best_odds_table_outlier_tolerance`). Real differences between bookmakers are a few percent; even a wide long-shot spread such as 21.0 / 23.0 / 26.0 stays within about 12%.
- Suspect prices are still shown, muted, with a "!" marker and an explanation (also for screen readers), but they are never highlighted as best; the best of the remaining prices is.
- With fewer than three prices there is no meaningful median, so nothing is judged.

Because it lives in the presenter, the page, the REST API and the editor preview all agree. The REST response carries a `suspect` flag per price.

---

## 7. Odds conversion

[`OddsConverter`](src/Conversion/OddsConverter.php) parses and formats all three formats. With *d* as decimal odds:

| From | Formula |
|---|---|
| Fractional *a/b* | *d* = *a/b* + 1 |
| American +*x* | *d* = *x*/100 + 1 |
| American −*x* | *d* = 100/*x* + 1 |
| Decimal to American, *d* ≥ 2 | +(*d* − 1) × 100 |
| Decimal to American, *d* < 2 | −100 / (*d* − 1) |

Decimal to fractional uses a **continued-fraction approximation** with a maximum denominator of 100, so 1.9091 becomes 10/11 rather than 9091/10000. UK convention is respected: 3/2 and 2/3 are shown as **6/4** and **4/6**.

| Fractional | Decimal | American |
|---|---|---|
| 1/1 (evens) | 2.00 | +100 |
| 6/4 | 2.50 | +150 |
| 13/5 | 3.60 | +260 |
| 10/11 | 1.91 | −110 |
| 4/6 | 1.67 | −150 |

```php
use BestOddsTable\Conversion\OddsConverter;
use BestOddsTable\Domain\OddsFormat;

$converter = new OddsConverter();
$converter->convert('6/4', OddsFormat::Fractional, OddsFormat::American); // "+150"
$converter->convert('1.9091', OddsFormat::Decimal, OddsFormat::Fractional); // "10/11"
$converter->convert('1.91', OddsFormat::Decimal, OddsFormat::Fractional);   // "91/100": exact, so not rounded to 10/11
```

---

## 8. Admin settings

**Settings → Best Odds Table** (capability `manage_options`), built on the Settings API, so WordPress handles the nonce, the capability check and saving.

| Section | What admins can do |
|---|---|
| **Status** | See how many pairs are watched, the last run's result and the next scheduled run; **Run fetch now** runs the same locked, batched fetch as cron. |
| **Odds source** | Choose the active source (only registered sources are listed). |
| **Markets** | Choose which markets editors may use. Blocks using a disabled market render nothing. |
| **Responsible gambling** | Edit the notice shown under every odds table (default: "18+ \| Please gamble responsibly … BeGambleAware.org"). Links, bold and italics are allowed; leave it empty to hide it. |
| **Bookmakers** | Enable or disable, rename, set the affiliate link, upload a logo (**Upload logo / Change logo / Remove**, using the Media Library, with a preview), and drag to reorder. The order is the default order in blocks. |

Stored options: `best_odds_table_bookmakers` (list of `{id, name, url, logo, enabled}`, where `logo` is the image's URL), `best_odds_table_markets` (list of market keys), `best_odds_table_source` (source id), `best_odds_table_notice` (notice HTML; absent means "use the translated default", empty means "hidden"). Until settings are saved, defaults come from [config/bookmakers.php](config/bookmakers.php) and [config/markets.php](config/markets.php).

[`SettingsSanitizer`](src/Admin/SettingsSanitizer.php) is plain PHP and unit tested: unknown bookmaker ids are dropped, missing ones are kept (disabled) so no configuration is lost, names are limited to 60 characters, and links must be `http` or `https`. It reports problems as codes, which `SettingsPage` turns into translated, escaped warnings.

**Logo check.** When a logo changes, saving asks the logo's server what the address returns (`wp_safe_remote_head()`, which refuses private network addresses). If it answers with a web page (for example a Wikimedia "File:" page instead of the image file) or an error, the logo is removed and the admin sees why. If the server cannot be reached, the address is kept: only what is provably wrong is rejected. Logos that fail to load later are hidden on the front end instead of showing a broken image.

**Notice HTML** is filtered with `wp_kses()` to `<a>`, `<strong>` and `<em>` when saving and again when printing.

---

## 9. The Gutenberg block

**Block name:** `best-odds-table/odds-table` (category Widgets). A dynamic block: only attributes are saved; `render.php` builds the markup on every request, so changing the markup never causes "invalid block" errors.

### Attributes

| Attribute | Type | Meaning |
|---|---|---|
| `eventId` | string | Event to show (from the picker). |
| `marketKey` | string | Market to show, e.g. `match_result`. |
| `bookmakers` | string[] | Bookmaker ids in display order. Empty means "all enabled, in settings order", so bookmakers enabled later appear without editing posts. |
| `format` | `decimal` \| `fractional` \| `american` | Default format. Visitors can switch. |

### Editor

- **Placeholder** with an event picker (searchable; shows teams, competition and kick-off) and a market select limited to markets the admin enabled.
- **Sidebar:** event and market, default format, and a **Bookmakers** panel: "Show all enabled bookmakers", or a custom list where editors add, remove and reorder bookmakers. The last bookmaker cannot be removed, because an empty list would silently mean "all".
- **Preview** is the real server render (`ServerSideRender`), so the editor shows exactly what visitors will see. It first calls `GET /odds`, which fetches a not-yet-watched pair once (if the source lists it), so the preview shows real odds straight away.

### Front end

- A responsive table: one row per bookmaker, one column per outcome, best price highlighted, bookmaker names (with logos, when set) linking out with `rel="sponsored nofollow noopener"`. On small screens the table scrolls sideways with the bookmaker column, header included, fixed.
- Event, competition, kick-off and market above the table; "Odds updated at …" and the responsible gambling notice below.
- **Suspect prices** (see [Suspect price guard](#suspect-price-guard)) are muted with a "!" marker and an explanation, and are never highlighted as best.
- **Format switch** with the Interactivity API ([view.js](blocks/odds-table/view.js)): it changes `data-format` on the wrapper and CSS shows the matching values. Each table starts with its editor default; once the visitor picks a format, it is treated as a reader preference: every table on the page switches at once (shared `state.chosen`, applied by a `data-wp-watch` callback per table) and the choice is remembered in `localStorage` (`bestOddsTableFormat`) for later pages. Tip when testing: after using the switch, your browser shows your chosen format everywhere; open a private window to see each table's editor default again.
- **Accessibility:** a real `<table>` with a caption and `scope` attributes, a labelled `<select>`, "(best price)", "No price" and the suspect-price explanation as text for screen readers, a `role="status"` notice for stale data.
- **Empty states:** visitors see nothing if no odds are cached; editors see "Odds are not available yet for this event and market."
- **Theming:** colours inherit from the theme; override the custom properties `--bot-best-bg`, `--bot-best-fg`, `--bot-border`, `--bot-muted`, `--bot-notice-bg`, `--bot-notice-fg` on `.wp-block-best-odds-table-odds-table`.

---

## 10. REST API

Namespace `best-odds-table/v1`.

| Route | Access | Purpose |
|---|---|---|
| `GET /odds` | Public | The odds table view model for one event and market. Reads cache only for visitors. |
| `GET /events` | `edit_posts` | Events and enabled markets for the block's picker. |
| `GET /bookmakers` | `edit_posts` | Enabled bookmakers, in settings order, for the block's sidebar. |

### `GET /odds`

| Parameter | Required | Notes |
|---|---|---|
| `event` | yes | Event id. |
| `market` | yes | Market key. |
| `bookmakers[]` | no | Ids in display order; default all enabled. |
| `format` | no | `decimal` (default), `fractional` or `american`. |

```
GET /wp-json/best-odds-table/v1/odds?event=ars-che-2026-10-18&market=match_result&bookmakers[]=bet365&bookmakers[]=unibet&format=fractional
```

```json
{
  "event": { "id": "ars-che-2026-10-18", "name": "Arsenal v Chelsea", "competition": "Premier League", "starts_at": "2026-10-18T14:00:00+00:00" },
  "market": { "key": "match_result", "label": "Match Result" },
  "outcomes": [ { "key": "home", "label": "Arsenal" }, { "key": "draw", "label": "Draw" }, { "key": "away", "label": "Chelsea" } ],
  "rows": [
    {
      "bookmaker": { "id": "bet365", "name": "bet365", "url": "https://www.bet365.com/", "logo": null },
      "prices": {
        "home": { "value": "1/1", "formats": { "decimal": "2.00", "fractional": "1/1", "american": "+100" }, "decimal": 2, "best": true, "suspect": false },
        "draw": { "...": "..." },
        "away": { "...": "..." }
      }
    }
  ],
  "format": "fractional",
  "fetched_at": "2026-10-14T11:48:29+00:00",
  "stale": false
}
```

Market and outcome names come translated (team names stay as the source gives them). Errors: `400 best_odds_table_invalid_request` (missing or invalid event/market), `404 best_odds_table_not_available` (no odds cached yet).

---

## 11. Hooks reference

### Filters

| Filter | Default | Use |
|---|---|---|
| `best_odds_table_active_source` | Saved setting, else `fixture` | Force a source id in code. |
| `best_odds_table_refresh_policy` | `new RefreshPolicy()` | Replace the refresh tiers. |
| `best_odds_table_fetch_batch_size` | `20` | Requests per cron run. |
| `best_odds_table_fetch_delay_us` | `250000` | Delay between requests, in microseconds. |
| `best_odds_table_odds_api_sports` | `['soccer_epl' => 'Premier League']` | The Odds API sport keys => competition names. |
| `best_odds_table_odds_api_bookmakers` | `OddsApiSource::BOOKMAKERS` | The Odds API bookmaker key => plugin bookmaker id. |
| `best_odds_table_odds_api_cache_ttl` | `7200` | Seconds a response is reused; lower it on a paid plan. |
| `best_odds_table_outlier_tolerance` | `0.5` | How far (share of the median implied probability) a price may be from the other bookmakers' before it is suspect. |
| `best_odds_table_litespeed_ttl` | `3600` | LiteSpeed Cache lifetime, in seconds, for pages that show odds. |

### Actions

| Action | Arguments | Fires when |
|---|---|---|
| `best_odds_table_register_sources` | `SourceRegistry $sources` | After built-in sources are registered; register your own. |
| `best_odds_table_fetched` | `WatchedMarket $pair, Event $event` | A pair was fetched and stored. |
| `best_odds_table_fetch_failed` | `WatchedMarket $pair, SourceException $e` | A fetch failed. |
| `best_odds_table_market_missing` | `WatchedMarket $pair` | The source no longer lists a watched pair. |
| `best_odds_table_fetch_completed` | `FetchReport $report` | After each cron run (status `ok`, `locked`, `backing_off` or `rate_limited`, with counts). |
| `best_odds_table_index_failed` | `SourceException $e` | The event index could not be refreshed. |
| `best_odds_table_rendered` | `string $eventId, string $marketKey` | An odds table rendered (also when empty). Page caches tag the page here. |
| `best_odds_table_source_changed` | `string $newSource` | The odds source changed and the previous source's data was dropped. |

### Examples

```php
// Refresh more often close to kick-off: 2 minutes in the last 3 hours.
add_filter('best_odds_table_refresh_policy', fn () => new \BestOddsTable\Fetch\RefreshPolicy(soonInterval: 120));

// Another page cache plugin: tag pages on render, purge by pair when odds change
// (the same pattern the built-in LiteSpeed integration uses).
add_action('best_odds_table_rendered', function ($eventId, $marketKey) {
    my_cache_tag_current_page('odds-' . md5($eventId . '|' . $marketKey));
}, 10, 2);
add_action('best_odds_table_fetched', function ($pair) {
    my_cache_purge_tag('odds-' . md5($pair->eventId . '|' . $pair->marketKey));
});

// Log failed fetches.
add_action('best_odds_table_fetch_failed', function ($pair, $e) {
    error_log(sprintf('[best-odds-table] %s failed: %s', $pair->key(), $e->getMessage()));
}, 10, 2);

// Be stricter about suspect prices: flag anything 30% away from the median.
add_filter('best_odds_table_outlier_tolerance', fn () => 0.3);
```

---

## 12. Extending the plugin

### Adding a source

1. Implement `OddsSourceInterface`:

```php
namespace MyPlugin;

use BestOddsTable\Domain\Event;
use BestOddsTable\Http\HttpClient;
use BestOddsTable\Source\EventFactory;
use BestOddsTable\Source\OddsSourceInterface;
use BestOddsTable\Source\SourceException;

final class MyFeedSource implements OddsSourceInterface
{
    public function __construct(
        private readonly HttpClient $http,
        private readonly EventFactory $factory,
    ) {
    }

    public function id(): string
    {
        return 'my-feed';
    }

    public function label(): string
    {
        return 'My odds feed';
    }

    public function fetchEvents(array $marketKeys = []): array
    {
        $response = $this->http->get('https://example.com/odds.json');

        if ($response->status === 429) {
            throw SourceException::rateLimited($this->id(), (int) ($response->header('Retry-After') ?? 60));
        }
        if ($response->status !== 200) {
            throw new SourceException(sprintf('My feed returned HTTP %d.', $response->status));
        }

        $events = [];
        foreach (json_decode($response->body, true) ?? [] as $row) {
            try {
                // Map your format to the normalised shape documented in EventFactory.
                $events[] = $this->factory->fromArray($this->normalise($row), $marketKeys);
            } catch (\InvalidArgumentException) {
                continue; // Skip one bad event, keep the rest.
            }
        }

        return $events;
    }

    public function fetchEvent(string $eventId, array $marketKeys = []): ?Event
    {
        foreach ($this->fetchEvents($marketKeys) as $event) {
            if ($event->id === $eventId) {
                return $event;
            }
        }

        return null;
    }

    private function normalise(array $row): array
    {
        return [
            'id' => $row['id'],
            'sport' => 'football',
            'competition' => $row['league'],
            'home' => $row['home'],
            'away' => $row['away'],
            'starts_at' => $row['kickoff'],          // ISO 8601
            'markets' => [[
                'key' => 'match_result',
                'label' => 'Match Result',
                'outcomes' => ['home' => $row['home'], 'draw' => 'Draw', 'away' => $row['away']],
                'prices' => $row['prices'],      // bookmaker id => outcome key => price string
            ]],
        ];
    }
}
```

2. Register it:

```php
add_action('best_odds_table_register_sources', function ($sources) {
    $sources->register('my-feed', fn () => new \MyPlugin\MyFeedSource(
        new \BestOddsTable\Http\WpHttpClient(),
        new \BestOddsTable\Source\EventFactory(new \BestOddsTable\Conversion\OddsConverter()),
    ));
});
```

3. Choose it in **Settings → Best Odds Table → Odds source**.

Rules for sources: never call them during a page request (the fetcher does that); throw `SourceException` on failure rather than returning an empty list, which would look like "no matches"; if one request returns many events, cache the response inside the source, as `OddsApiSource` does; set `updated_at` if you serve cached data. [`OddsApiSource`](src/Source/OddsApiSource.php) and its [test](tests/Unit/OddsApiSourceTest.php) are a complete worked example.

### Adding a bookmaker

Add it to [config/bookmakers.php](config/bookmakers.php) (`id`, `name`, `url`), then enable it and set its link in Settings. For The Odds API, map its key with the `best_odds_table_odds_api_bookmakers` filter:

```php
add_filter('best_odds_table_odds_api_bookmakers', fn ($map) => $map + ['betvictor' => 'betvictor']);
```

### Adding a market

Add it to [config/markets.php](config/markets.php) (`key => label`) and make sure the active source returns it (markets in the normalised array, with outcome keys and labels). Admins can then enable it, and editors can pick it. The presenter and block need no changes: outcomes come from the data.

### Adding a competition (The Odds API)

```php
add_filter('best_odds_table_odds_api_sports', fn ($sports) => $sports + ['soccer_spain_la_liga' => 'La Liga']);
```

Each sport is one request per refresh, so check the quota.

---

## 13. Security

- **Capabilities.** The settings page and "Run fetch now" require `manage_options`; `GET /events` and `GET /bookmakers` require `edit_posts`; `GET /odds` is public but read-only.
- **Nonces.** Settings saves use the Settings API nonce; "Run fetch now" uses `check_admin_referer()`. Cookie-authenticated REST requests need the REST nonce, so the editor-only fetch path cannot be triggered by CSRF.
- **Input.** REST arguments are sanitised and `format` is an enum; settings go through `SettingsSanitizer`, `esc_url_raw()` (http/https only) and, for the notice, `wp_kses()`; event ids and market keys are validated against strict patterns wherever they enter (block attributes, REST, source data). The one query-string value read directly (the "fetch now" result) is unslashed, reduced with `sanitize_key()` and only used to pick a message from a fixed list.
- **Output.** Everything is escaped where it is printed (`esc_html`, `esc_attr`, `esc_url`, `esc_textarea`, `wp_kses`); data from sources (team names, labels) is treated as untrusted. **Settings messages are escaped before `add_settings_error()`**, because WordPress prints them without escaping, and one of them reports what a remote server answered (whose Content-Type is additionally reduced to MIME-type characters).
- **Outgoing requests.** The logo check uses `wp_safe_remote_head()`, which refuses private and loopback addresses, so an admin-entered URL cannot be used to probe the server's network.
- **Database.** The only direct queries (the fetch lock and `uninstall.php`) go through `$wpdb->prepare()`.
- **Abuse resistance.** Editors cannot make the plugin send unlimited requests: on-demand fetches are limited to pairs the source lists and respect the backoff; drafts do not add to the watch list; never-found ids are retried only hourly.
- **Secrets.** The API key lives in `wp-config.php`, never in the database or the repository, and never appears in error messages.

---

## 14. Translations

Every visible string is translatable with the text domain `best-odds-table`: PHP with `__()`/`esc_html__()` and translator comments for placeholders, the editor with `@wordpress/i18n` (translations load through `block.json`'s `textdomain`).

Plain-PHP classes cannot call WordPress translation functions and stay unit testable, so they never produce user-facing sentences:

- `SettingsSanitizer` reports **codes** (`invalid_link`, `web_page`, …) that `SettingsPage` turns into translated, escaped messages.
- Sources receive their **display name** through the constructor; `Plugin` passes a translated one.
- Sources name markets and outcomes in English as data. `Plugin::labels()` holds the translated names (Match Result, Both Teams To Score, Draw, Yes, No), and the presenter, the `/events` endpoint and the settings page use them for the keys they cover. Team and competition names are shown as the source gives them.

Translations load only after `init`; the few strings needed by objects built earlier (source names, the settings page's market list) are created lazily, so WordPress 6.7+ never reports translations loaded too early. A `.pot` file can be generated with `wp i18n make-pot . languages/best-odds-table.pot`.

---

## 15. Testing and code quality

```bash
composer test      # PHPUnit: 92 tests, no WordPress needed
composer lint      # PHPCS, PSR-12 (PHP only)
npm run lint:js    # ESLint, WordPress rules (block and settings page scripts)
npm run lint:css   # Stylelint, WordPress rules (block and settings page styles)
npm run build      # Rebuild build/ after changing blocks/
```

Tests cover the converter (formulas, continued fractions, conventional fractions), the refresh policy, the watch list and block scanner, the fetcher (batching, lock, backoff, missing events, on-demand guards, source reset), the repositories and two-layer cache, the presenter (best prices, display names, the suspect price guard), the block's view model, the settings sanitizer (including the logo check and warning codes), the fixture's rolling dates and the API source against a **recorded real response** (mapping, quota control, rate limits, no key leaks). Storage is doubled by `tests/Support/InMemoryStore`; HTTP by a recording fake.

The WordPress-bound pieces were checked against a real site: the atomic lock (taken, refused while held, released, stale lock taken over), `uninstall.php` (run inside a rolled-back transaction: every plugin option, transient and cron job removed, nothing else), the block and settings page rendering without notices, and the front end in a real browser at desktop and phone widths.

---

## 16. Deployment and uninstall

- **Requirements:** PHP 8.1+, WordPress 6.6+. Install PHP dependencies with `composer install --no-dev`; `build/` is committed.
- **Release package.** A plugin zip for a live site contains only `best-odds-table.php`, `uninstall.php`, `src/`, `build/`, `views/`, `assets/`, `config/`, `fixtures/`, `vendor/` (from `composer install --no-dev --optimize-autoloader`; the plugin has no runtime dependencies, so it holds only the autoloader), `README.md` and `DOCS.md`. Development files (`blocks/` sources, `tests/`, `node_modules/`, `composer.*`, `package*.json`, linter and PHPUnit configs, editor or assistant notes) stay out: a web server serves them to anyone. Zip entries must use forward slashes: Windows PowerShell's `Compress-Archive` writes backslashes, which a Linux server unpacks as file names instead of folders, and WordPress then reports "Plugin file does not exist". Upload with **Plugins → Add New → Upload Plugin → Replace current with uploaded**; replacing does not run `uninstall.php`, so settings stay.
- **Cron.** WP-Cron only runs when someone visits the site. On a quiet site add a real cron job that requests `wp-cron.php` every 5 minutes and set `define('DISABLE_WP_CRON', true);`.
- **Object cache.** With Redis or Memcached (e.g. LiteSpeed Cache's object cache), transients use it automatically; no code change is needed. The fetch lock deliberately uses the database, so it stays atomic either way.
- **LiteSpeed Cache.** Page cache purging works out of the box (see [Performance on the front end](#performance-on-the-front-end)). If you enable JavaScript optimisation ("Load JS Deferred", "JS Delayed" or "JS Combine"), add `interactivity` and `best-odds-table` to **JS Excludes**: the format switch is a JavaScript module loaded through an import map, which combining or delaying can break. Logged-in users are not cached by default, so editors always see the live block.
- **Live odds.** Add `define('BEST_ODDS_TABLE_ODDS_API_KEY', '…');` to `wp-config.php` and choose the source in Settings.
- **New pairs.** Saving a post queues a one-off fetch for pairs nobody watched before, so new blocks fill within seconds on a site with working cron.
- **Deactivating** stops the scheduled jobs and keeps all settings, so reactivating restores everything.
- **Deleting** the plugin runs [uninstall.php](uninstall.php): it removes every `best_odds_table_*` option, the plugin's own transients (matched by exact key, because the transient prefix is short and could match another plugin's) and its scheduled jobs, on every site of a multisite network. Odds held only in a persistent object cache are not listed by WordPress and simply expire.

---

## 17. Known limitations and next steps

- **BTTS from the live source.** It costs one request per event on The Odds API, so the live source offers Match Result only. Enabling it is a plan decision, not a code limitation.
- **bet365** is not offered by The Odds API; it appears with the fixture source only.
- **Storage scale.** Options suit tens to hundreds of watched pairs. For thousands, or for price history and "odds movement" arrows, a custom table would be the next step.
- **Suspect prices are judged at display time** and not logged. A `best_odds_table_price_suspect` action from the fetcher would let a site alert on bad feed data.
- **Format choice** is remembered per browser, not per account, so a logged-in reader's preference does not follow them to another device.
- **Ideas for later:** bookmaker margins and a sure-bet (arbitrage) indicator from the best prices; odds movement arrows; Site Health checks (cron running, source reachable, API credits left); WP-CLI commands (`status`, `fetch`, `reset-source`); exposing odds through the WordPress Abilities API for AI agents.
