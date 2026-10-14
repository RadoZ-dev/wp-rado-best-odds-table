<?php

declare(strict_types=1);

namespace BestOddsTable\Rest;

use InvalidArgumentException;
use BestOddsTable\Domain\Bookmaker;
use BestOddsTable\Domain\OddsFormat;
use BestOddsTable\Fetch\OddsFetcher;
use BestOddsTable\Presentation\OddsTablePresenter;
use BestOddsTable\Repository\BookmakerRepository;
use BestOddsTable\Repository\MarketRepository;
use BestOddsTable\Repository\OddsRepository;
use BestOddsTable\Source\SourceException;
use BestOddsTable\Watch\WatchedMarket;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * REST API under /wp-json/best-odds-table/v1.
 *
 *  GET /odds        public; reads the cache only, never calls the source
 *                   for anonymous visitors.
 *  GET /events      editors; available events for the block's picker.
 *  GET /bookmakers  editors; enabled bookmakers for the block's inspector.
 */
final class OddsController
{
    public const NAMESPACE = 'best-odds-table/v1';

    /**
     * @param array<string, string> $marketLabels Market key => translated display name.
     */
    public function __construct(
        private readonly OddsRepository $odds,
        private readonly BookmakerRepository $bookmakers,
        private readonly MarketRepository $markets,
        private readonly OddsTablePresenter $presenter,
        private readonly OddsFetcher $fetcher,
        private readonly array $marketLabels = [],
    ) {
    }

    public function register(): void
    {
        register_rest_route(self::NAMESPACE, '/odds', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'odds'],
            'permission_callback' => '__return_true',
            'args' => [
                'event' => ['type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field'],
                'market' => ['type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_key'],
                'bookmakers' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'default' => [],
                    'sanitize_callback' => static fn ($ids): array => array_map('sanitize_key', (array) $ids),
                ],
                'format' => [
                    'type' => 'string',
                    'enum' => array_column(OddsFormat::cases(), 'value'),
                    'default' => OddsFormat::Decimal->value,
                ],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/events', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'events'],
            'permission_callback' => [$this, 'canEdit'],
        ]);

        register_rest_route(self::NAMESPACE, '/bookmakers', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'listBookmakers'],
            'permission_callback' => [$this, 'canEdit'],
        ]);
    }

    public function canEdit(): bool
    {
        return current_user_can('edit_posts');
    }

    public function odds(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        try {
            $pair = new WatchedMarket((string) $request['event'], (string) $request['market']);
        } catch (InvalidArgumentException) {
            return new WP_Error('best_odds_table_invalid_request', __('Event and market are required.', 'best-odds-table'), ['status' => 400]);
        }

        $cached = $this->odds->find($pair);

        // Editors previewing a block for a pair nobody watches yet get a one-off
        // fetch so the editor is not empty. Visitors never trigger a fetch, and
        // fetchOnDemand() refuses unlisted pairs and respects the backoff.
        if ($cached === null && $this->canEdit()) {
            try {
                $this->fetcher->fetchOnDemand($pair, time());
                $cached = $this->odds->find($pair);
            } catch (SourceException) {
                // Fall through to "not available".
            }
        }

        if ($cached === null) {
            return new WP_Error('best_odds_table_not_available', __('Odds are not available yet.', 'best-odds-table'), ['status' => 404]);
        }

        $data = $this->presenter->present(
            $cached,
            $this->bookmakers->enabled((array) $request['bookmakers']),
            OddsFormat::fromInput((string) $request['format'])
        );

        $response = new WP_REST_Response($data);
        // Let a CDN/page cache absorb traffic; odds refresh every few minutes at most.
        $response->header('Cache-Control', $cached->stale ? 'no-store' : 'public, max-age=60');

        return $response;
    }

    public function events(): WP_REST_Response
    {
        $index = $this->odds->index();

        if ($index === null) {
            try {
                $index = $this->fetcher->refreshIndex();
            } catch (SourceException) {
                $index = [];
            }
        }

        return new WP_REST_Response($this->onlyEnabledMarkets($index));
    }

    /**
     * Editors may only pick markets the admin enabled. Events left with no
     * enabled market are dropped from the picker.
     *
     * @param list<array<string, mixed>> $index
     *
     * @return list<array<string, mixed>>
     */
    private function onlyEnabledMarkets(array $index): array
    {
        $enabled = $this->markets->enabled();
        $filtered = [];

        foreach ($index as $event) {
            $markets = array_intersect_key((array) ($event['markets'] ?? []), $enabled);
            // Translated names for known markets; others as the source named them.
            foreach ($markets as $key => $label) {
                $markets[$key] = $this->marketLabels[$key] ?? $label;
            }
            $event['markets'] = $markets;
            if ($event['markets'] !== []) {
                $filtered[] = $event;
            }
        }

        return $filtered;
    }

    public function listBookmakers(): WP_REST_Response
    {
        return new WP_REST_Response(array_map(
            static fn (Bookmaker $bookmaker): array => $bookmaker->toArray(),
            $this->bookmakers->enabled()
        ));
    }
}
