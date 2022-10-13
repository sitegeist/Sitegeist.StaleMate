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
                $this->logUpdate(true, $identifier, $lifeTime, $gracePeriod, $tags);
                $cacheItem = Item::createFromValueAndGracePeriod($value, $gracePeriod);
                $this->cache->set($identifier, $cacheItem, $tags, $lifeTime + $gracePeriod);
            } catch (\Exception $exception) {
                $this->logException($exception, $identifier, $lifeTime, $gracePeriod, $tags);
                throw $exception;
            }
            return $value;
        }
    }

    public function shutdownObject(): void
    {
        foreach ($this->closuresToUpdate as $identifier => $item) {
            try {
                if ($item->getLockPeriod() && $item->getLockPeriod() > 0) {
                    if ($this->cache->has($item->getIdentifier() . self::LOCKED)) {
                        $this->logSkip(false, $identifier, $item->getLifeTime(), $item->getGracePeriod(), $item->getTags());
                        continue;
                    } else {
                        $this->cache->set($item->getIdentifier() . self::LOCKED, true, $item->getTags(), $item->getLockPeriod());
                    }
                }
                $value = $item->getClosure()();
                $this->logUpdate(false, $identifier, $item->getLifeTime(), $item->getGracePeriod(), $item->getTags());
                $cacheItem = Item::createFromValueAndGracePeriod($value, $item->getGracePeriod());
                $this->cache->set($identifier, $cacheItem, $item->getTags(), $item->getLifeTime() + $item->getGracePeriod());
            } catch (\Exception $e) {
                $this->logException($e, $identifier, $item->getLifeTime(), $item->getGracePeriod(), $item->getTags());
            }
        }
    }

    /**
     * @param bool $synchronous
     * @param string $identifier
     * @param int|null $lifeTime
     * @param int|null $gracePeriod
     * @param string[] $tags
     * @return void
     */
    protected function logSkip(bool $synchronous, string $identifier, ?int $lifeTime, ?int $gracePeriod, array $tags): void
    {
        if ($this->logger) {
            $this->logger->info(sprintf('StaleMate skipped update of %s %s', $identifier, $synchronous ? 'sync' : 'async'), get_defined_vars());
        }
    }

    /**
     * @param bool $synchronous
     * @param string $identifier
     * @param int|null $lifeTime
     * @param int|null $gracePeriod
     * @param string[] $tags
     * @return void
     */
    protected function logUpdate(bool $synchronous, string $identifier, ?int $lifeTime, ?int $gracePeriod, array $tags): void
    {
        if ($this->logger) {
            $this->logger->info(sprintf('StaleMate item %s was updated %s', $identifier, $synchronous ? 'sync' : 'async'), get_defined_vars());
        }
    }

    /**
     * @param \Exception $exception
     * @param string $identifier
     * @param int|null $lifeTime
     * @param int|null $gracePeriod
     * @param string[] $tags
     * @return void
     */
    protected function logException(\Exception $exception, string $identifier, ?int $lifeTime, ?int $gracePeriod, array $tags): void
    {
        if ($this->logger) {
            $this->logger->error(sprintf('StaleMate Update failed for %s with message %s', $identifier, $exception->getMessage()), get_defined_vars());
        }
    }
}
