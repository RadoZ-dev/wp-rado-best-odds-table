<?php

declare(strict_types=1);

namespace BestOddsTable\Source;

use InvalidArgumentException;

/**
 * Registry + factory for odds sources.
 *
 * Sources are registered as factories (closures) and only instantiated when
 * first requested, so registering many providers costs nothing on requests
 * that never fetch odds.
 */
final class SourceRegistry
{
    /** @var array<string, callable(): OddsSourceInterface> */
    private array $factories = [];

    /** @var array<string, OddsSourceInterface> */
    private array $instances = [];

    /**
     * @param callable(): OddsSourceInterface $factory
     */
    public function register(string $id, callable $factory): void
    {
        $this->factories[$id] = $factory;
        unset($this->instances[$id]);
    }

    public function has(string $id): bool
    {
        return isset($this->factories[$id]);
    }

    public function get(string $id): OddsSourceInterface
    {
        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }

        if (!$this->has($id)) {
            throw new InvalidArgumentException(sprintf('Unknown odds source "%s".', $id));
        }

        $source = ($this->factories[$id])();

        if (!$source instanceof OddsSourceInterface) {
            throw new InvalidArgumentException(
                sprintf('Factory for "%s" must return %s.', $id, OddsSourceInterface::class)
            );
        }

        return $this->instances[$id] = $source;
    }

    /**
     * @return list<string>
     */
    public function ids(): array
    {
        return array_keys($this->factories);
    }
}
