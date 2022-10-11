<?php
declare(strict_types=1);

namespace Sitegeist\StaleMate\Cache;

use Neos\Flow\Annotations as Flow;
use Neos\Cache\Frontend\VariableFrontend;
use Psr\Log\LoggerInterface;

class StaleMateCache
{
    /**
     * @var VariableFrontend
     * @Flow\Inject
     */
    protected $cache;

    /**
     * @var LoggerInterface
     * @Flow\Inject
     */
    protected $logger;

    /**
     * @var array<string, array>
     */
    protected $closuresToUpdate = [];

    /**
     * @param string $identifier identifier used to store the result, has to be unique
     * @param \Closure $updateClosure closure to generate the result if no result is in the cache
     * @param int $lifeTime lifetime of the cached result until a refresh is needed
     * @param int $staleTime period after lifetime where an update is performed async and the stale result is used
     * @param array $tags tags for the cache item
     *
     * @return mixed
     */
    public function get(string $identifier, \Closure $updateClosure, int $lifeTime, int $staleTime, array $tags = [])
    {
        $valueFromCache = $this->cache->get($identifier);
        if ($valueFromCache) {
            if ($valueFromCache['timestamp'] < time() - $staleTime) {
                $this->closuresToUpdate[$identifier] = ['closure' => $updateClosure, 'lifeTime' => $lifeTime, 'staleTime' => $staleTime, 'tags' => $tags];
            }
            return $valueFromCache['value'];
        } else {
            try {
                $value = $updateClosure();
                $this->logUpdate(true, $identifier, $lifeTime, $staleTime, $tags);
                $this->cache->set($identifier, ['value' => $value, 'timestamp' => time()], $tags, $lifeTime + $staleTime);
            } catch (\Exception $e) {
                $this->logException($e, $identifier, $lifeTime, $staleTime, $tags);
            }
            return $value;
        }
    }

    public function remove(string $identifier)
    {
        $this->cache->remove($identifier);
    }

    public function flushByTags(array $tags)
    {
        if (method_exists($this->cache, 'flushByTags')) {
            $this->cache->flushByTags($tags);
        } else {
            foreach ($tags as $tag) {
                $this->cache->flushByTag($tag);
            }
        }
    }

    public function shutdownObject()
    {
        foreach ($this->closuresToUpdate as $identifier => $item) {
            try {
                $value = $item['closure']();
                $this->logUpdate(false,$identifier,  $item['lifeTime'] ,  $item['staleTime'], $item['tags']);
                $this->cache->set($identifier, ['value' => $value, 'timestamp' => time()], $item['tags'], $item['lifeTime'] + $item['staleTime']);
            } catch (\Exception $e) {
                $this->logException($e, $identifier, $item['lifeTime'] ,  $item['staleTime'], $item['tags']);
            }
        }
    }

    protected function logUpdate(bool $synchronous, string $identifier, int $lifeTime, int $staleTime, array $tags): void
    {
        $this->logger->info(sprintf('StaleMate item %s was updated %s', $identifier, $synchronous ? 'sync' : 'async'), get_defined_vars());
    }

    protected function logException(\Exception $exception, string $identifier, int $lifeTime, int $staleTime, array $tags): void
    {
        $this->logger->error(sprintf('StaleMate Update failed for %s with message %s', $identifier, $exception->getMessage()), get_defined_vars());
    }
}
