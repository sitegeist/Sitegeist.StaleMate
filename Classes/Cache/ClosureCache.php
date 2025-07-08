<?php

declare(strict_types=1);

namespace Sitegeist\StaleMate\Cache;

use Neos\Cache\Frontend\VariableFrontend;
use Psr\Log\LoggerInterface;

class ClosureCache
{
    const LOCKED = '-LOCKED';

    /**
     * @var VariableFrontend
     */
    protected $cache;

    /**
     * @var int
     */
    protected $defaultLifetime;

    /**
     * @var int
     */
    protected $defaultGracePeriod;

    /**
     * @var int|null
     */
    protected $defaultLockPeriod;

    /**
     * @var LoggerInterface|null
     */
    protected $logger;

    /**
     * @var array<string, RefreshRequest>
     */
    protected $closuresToUpdate = [];

    public function __construct(VariableFrontend $cache, int $lifeTime, int $gracePeriod, ?int $lockPeriod = null)
    {
        $this->cache = $cache;
        $this->defaultLifetime = $lifeTime;
        $this->defaultGracePeriod = $gracePeriod;
        $this->defaultLockPeriod = $lockPeriod;
    }

    public function injectLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    /**
     * @param string $identifier identifier used to store the result, has to be unique
     * @param \Closure $updateClosure closure to generate the result if no result is in the cache
     * @param int $lifeTime lifetime of the cached result until a refresh is needed
     * @param int $gracePeriod period after lifetime where an update is performed async and the stale result is used
     * @param int $lockPeriod period after lifetime where an update is performed async and the stale result is used
     * @param string[] $tags tags for the cache item
     *
     * @return mixed
     */
    public function resolve(string $identifier, \Closure $updateClosure, array $tags = [], ?int $lifeTime = null, ?int $gracePeriod = null, ?int $lockPeriod = null)
    {
        $lifeTime = $lifeTime ?: $this->defaultLifetime;
        $gracePeriod = $gracePeriod ?: $this->defaultGracePeriod;
        $lockPeriod = $lockPeriod ?: $this->defaultLockPeriod;

        $cacheItem = $this->cache->get($identifier);
        if ($cacheItem && $cacheItem instanceof Item) {
            if ($cacheItem->isRefreshRequired()) {
                $this->closuresToUpdate[$identifier] = new RefreshRequest($identifier, $updateClosure, $lifeTime, $gracePeriod, $lockPeriod, $tags);
            }
            return $cacheItem->getValue();
        } else {
            try {
                $value = $updateClosure();
                $cacheItem = Item::createFromValueAndGracePeriod($value, $gracePeriod);
                $this->cache->set($identifier, $cacheItem, $tags, $lifeTime + $gracePeriod);
                $this->logUpdate($identifier, false);
                return $value;
            } catch (\Exception $exception) {
                $this->logException($identifier, $exception, false);
                throw $exception;
            }
        }
    }

    public function shutdownObject(): void
    {
        foreach ($this->closuresToUpdate as $identifier => $item) {
            try {
                if ($item->getLockPeriod() && $item->getLockPeriod() > 0) {
                    if ($this->cache->has($item->getIdentifier() . self::LOCKED)) {
                        $this->logSkip($identifier, true);
                        continue;
                    } else {
                        $this->cache->set($item->getIdentifier() . self::LOCKED, true, $item->getTags(), $item->getLockPeriod());
                    }
                }
                $value = $item->getClosure()();
                $cacheItem = Item::createFromValueAndGracePeriod($value, $item->getGracePeriod());
                $this->cache->set($identifier, $cacheItem, $item->getTags(), $item->getLifeTime() + $item->getGracePeriod());
                $this->logUpdate($identifier, true);
            } catch (\Exception $exception) {
                $this->logException($identifier, $exception, true);
            }
        }
    }

    public function logSkip(string $identifier, bool $async): void
    {
        if ($this->logger) {
            $this->logger->info(sprintf('StaleMate item %s:%s was skipped (%s)', $this->cache->getIdentifier(), $identifier, $async ? 'ASYNC' : 'SYNC'));
        }
    }

    public function logUpdate(string $identifier, bool $async): void
    {
        if ($this->logger) {
            $this->logger->info(sprintf('StaleMate item %s:%s was updated (%s)', $this->cache->getIdentifier(), $identifier, $async ? 'ASYNC' : 'SYNC'));
        }
    }

    public function logException(string $identifier, \Exception $exception, bool $async): void
    {
        if ($this->logger) {
            $this->logger->error(sprintf('StaleMate update failed for %s:%s (%s) with message %s', $this->cache->getIdentifier(), $identifier, $async ? 'ASYNC' : 'SYNC', $exception->getMessage()), get_defined_vars());
        }
    }
}
