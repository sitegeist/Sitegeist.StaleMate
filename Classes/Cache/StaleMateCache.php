<?php

declare(strict_types=1);

namespace Sitegeist\StaleMate\Cache;

use Neos\Cache\Frontend\VariableFrontend;
use Psr\Log\LoggerInterface;

class StaleMateCache
{
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
    protected $defaultRetryInterval;

    /**
     * @var LoggerInterface|null
     */
    protected $logger;

    /**
     * @var array<string, array>
     */
    protected $closuresToUpdate = [];

    public function __construct(VariableFrontend $cache, int $lifeTime, int $gracePeriod, ?int $retryInterval = null)
    {
        $this->cache = $cache;
        $this->defaultLifetime = $lifeTime;
        $this->defaultGracePeriod = $gracePeriod;
        $this->defaultRetryInterval = $retryInterval;
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
     * @param string[] $tags tags for the cache item
     *
     * @return mixed
     */
    public function resolve(string $identifier, \Closure $updateClosure, array $tags = [], ?int $lifeTime = null, ?int $gracePeriod = null, ?int $retryInterval = null)
    {
        $lifeTime = $lifeTime ?: $this->defaultLifetime;
        $gracePeriod = $gracePeriod ?: $this->defaultGracePeriod;

        $valueFromCache = $this->cache->get($identifier);
        if ($valueFromCache) {
            if ($valueFromCache['timestamp'] < time() - $gracePeriod) {
                $this->closuresToUpdate[$identifier] = ['closure' => $updateClosure, 'lifeTime' => $lifeTime, 'gracePeriod' => $gracePeriod, 'tags' => $tags];
            }
            return $valueFromCache['value'];
        } else {
            try {
                $value = $updateClosure();
                $this->logUpdate(true, $identifier, $lifeTime, $gracePeriod, $tags);
                $this->cache->set($identifier, ['value' => $value, 'timestamp' => time()], $tags, $lifeTime + $gracePeriod);
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
                $value = $item['closure']();
                $this->logUpdate(false, $identifier, $item['lifeTime'], $item['gracePeriod'], $item['tags']);
                $this->cache->set($identifier, ['value' => $value, 'timestamp' => time()], $item['tags'], $item['lifeTime'] + $item['gracePeriod']);
            } catch (\Exception $e) {
                $this->logException($e, $identifier, $item['lifeTime'], $item['gracePeriod'], $item['tags']);
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
