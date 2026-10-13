<?php

declare(strict_types=1);

namespace BestOddsTable\Domain;

/**
 * A bookmaker as configured by the site (name, outbound link, logo).
 */
final class Bookmaker
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $url,
        public readonly ?string $logoUrl = null,
    ) {
    }

    /**
     * @param array{id: string, name: string, url?: string, logo?: string|null} $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (string) $data['id'],
            name: (string) $data['name'],
            url: (string) ($data['url'] ?? ''),
            logoUrl: isset($data['logo']) ? (string) $data['logo'] : null,
        );
    }

    /**
     * @return array{id: string, name: string, url: string, logo: string|null}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'url' => $this->url,
            'logo' => $this->logoUrl,
        ];
    }
}
