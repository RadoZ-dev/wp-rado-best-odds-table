<?php

declare(strict_types=1);

namespace BestOddsTable\Admin;

/**
 * Cleans settings submitted from the admin page.
 *
 * Pure PHP so every rule is unit tested; SettingsPage adds the WordPress
 * layer (esc_url_raw, settings errors) on top.
 *
 * Every method is idempotent: WordPress runs a sanitize callback twice when
 * an option is saved for the first time (update_option falls through to
 * add_option, which sanitizes again), so already-clean data must pass
 * through unchanged.
 */
final class SettingsSanitizer
{
    private const NAME_MAX_LENGTH = 60;

    /**
     * @param list<array{id: string, name: string, url: string}> $bookmakerCatalogue
     * @param array<string, string>                             $marketCatalogue
     */
    public function __construct(
        private readonly array $bookmakerCatalogue,
        private readonly array $marketCatalogue,
    ) {
    }

    /**
     * Accepts the form shape (keyed by bookmaker id) or the stored shape
     * (a list of rows). Unknown ids are dropped: the catalogue decides which
     * bookmakers exist, the admin only configures them.
     *
     * @return array{0: list<array{id: string, name: string, url: string, logo: string|null, enabled: bool}>, 1: list<string>}
     *         Clean rows in submitted order, and human readable warnings.
     */
    public function bookmakers(mixed $input): array
    {
        $defaults = [];
        foreach ($this->bookmakerCatalogue as $row) {
            $defaults[$row['id']] = $row;
        }

        $rows = [];
        $warnings = [];

        foreach (is_array($input) ? $input : [] as $key => $row) {
            if (!is_array($row)) {
                continue;
            }

            $id = (string) ($row['id'] ?? $key);
            if (!isset($defaults[$id]) || isset($rows[$id])) {
                continue;
            }

            $name = $this->text($row['name'] ?? '');
            if ($name === '') {
                $name = $defaults[$id]['name'];
            }

            $url = $this->url($row['url'] ?? '');
            if ($url === null) {
                $warnings[] = sprintf('The link for %s was not a valid http(s) address and was reset to the default.', $name);
            }
            if ($url === null || $url === '') {
                $url = $defaults[$id]['url'];
            }

            $logo = $this->url($row['logo'] ?? '');
            if ($logo === null) {
                $warnings[] = sprintf('The logo for %s was not a valid http(s) address and was removed.', $name);
            }

            $rows[$id] = [
                'id' => $id,
                'name' => $name,
                'url' => $url,
                'logo' => $logo === '' ? null : $logo,
                'enabled' => !empty($row['enabled']),
            ];
        }

        // Keep the stored list complete: bookmakers missing from the input
        // are appended, disabled, so no configuration is ever lost.
        foreach ($defaults as $id => $default) {
            $rows[$id] ??= [
                'id' => $id,
                'name' => $default['name'],
                'url' => $default['url'],
                'logo' => null,
                'enabled' => false,
            ];
        }

        return [array_values($rows), $warnings];
    }

    /**
     * @return array{0: list<string>, 1: list<string>} Enabled market keys in catalogue order, and warnings.
     */
    public function markets(mixed $input): array
    {
        $submitted = array_map('strval', is_array($input) ? $input : []);
        $keys = array_values(array_filter(
            array_keys($this->marketCatalogue),
            static fn (string $key): bool => in_array($key, $submitted, true)
        ));

        if ($keys === []) {
            return [array_keys($this->marketCatalogue), ['At least one market must stay enabled, so all markets were kept.']];
        }

        return [$keys, []];
    }

    /**
     * @param list<string> $registeredIds
     */
    public function source(mixed $input, array $registeredIds, string $default): string
    {
        $id = is_string($input) ? $input : '';

        return in_array($id, $registeredIds, true) ? $id : $default;
    }

    private function text(mixed $value): string
    {
        $text = trim((string) preg_replace('/\s+/', ' ', strip_tags((string) $value)));

        return mb_substr($text, 0, self::NAME_MAX_LENGTH);
    }

    /**
     * @return string|null Empty string when blank, null when invalid.
     */
    private function url(mixed $value): ?string
    {
        $url = trim((string) $value);

        if ($url === '') {
            return '';
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        if (filter_var($url, FILTER_VALIDATE_URL) === false || !in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        return $url;
    }
}
