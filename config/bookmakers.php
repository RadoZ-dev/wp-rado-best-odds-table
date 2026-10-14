<?php

/**
 * Default bookmaker catalogue, used to seed the settings on activation.
 * Administrators can enable/disable bookmakers and edit links in wp-admin.
 *
 * @return list<array{id: string, name: string, url: string}>
 */

declare(strict_types=1);

return [
    ['id' => 'bet365', 'name' => 'bet365', 'url' => 'https://www.bet365.com/'],
    ['id' => 'william-hill', 'name' => 'William Hill', 'url' => 'https://www.williamhill.com/'],
    ['id' => 'paddy-power', 'name' => 'Paddy Power', 'url' => 'https://www.paddypower.com/'],
    ['id' => 'betfair', 'name' => 'Betfair', 'url' => 'https://www.betfair.com/'],
    ['id' => 'sky-bet', 'name' => 'Sky Bet', 'url' => 'https://www.skybet.com/'],
    ['id' => 'ladbrokes', 'name' => 'Ladbrokes', 'url' => 'https://www.ladbrokes.com/'],
    ['id' => 'coral', 'name' => 'Coral', 'url' => 'https://www.coral.co.uk/'],
    ['id' => 'betfred', 'name' => 'Betfred', 'url' => 'https://www.betfred.com/'],
    ['id' => 'unibet', 'name' => 'Unibet', 'url' => 'https://www.unibet.com/'],
    ['id' => 'betway', 'name' => 'Betway', 'url' => 'https://www.betway.com/'],
];
