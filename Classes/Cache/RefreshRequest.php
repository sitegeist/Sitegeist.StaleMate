<?php

declare(strict_types=1);

namespace Sitegeist\StaleMate\Cache;

class RefreshRequest
{
    /**
     * @var string
     */
    protected $identifier;

    /**
     * @var \Closure
     */
    protected $closure;

    /**
     * @var int
     */
    protected $lifeTime;

    /**
     * @var int|null
     */
    protected $gracePeriod;

    /**
     * @var int|null
     */
    protected $lockPeriod;

    /**
     * @var string[]
     */
    protected $tags;

    /**
     * @param string $identifier
     * @param \Closure $closure
     * @param int $lifeTime
     * @param int|null $gracePeriod
     * @param int|null $lockPeriod
     * @param string[] $tags
     */
    public function __construct(string $identifier, \Closure $closure, int $lifeTime, ?int $gracePeriod, ?int $lockPeriod, array $tags)
    {
        $this->identifier = $identifier;
        $this->closure = $closure;
        $this->lifeTime = $lifeTime;
        $this->gracePeriod = $gracePeriod;
        $this->lockPeriod = $lockPeriod;
        $this->tags = $tags;
    }

    /**
     * @return string
     */
    public function getIdentifier(): string
    {
        return $this->identifier;
    }

    /**
     * @return \Closure
     */
    public function getClosure(): \Closure
    {
        return $this->closure;
    }

    /**
     * @return int
     */
    public function getLifeTime(): int
    {
        return $this->lifeTime;
    }

    /**
     * @return int|null
     */
    public function getGracePeriod(): ?int
    {
        return $this->gracePeriod;
    }

    /**
     * @return int|null
     */
    public function getLockPeriod(): ?int
    {
        return $this->lockPeriod;
    }

    /**
     * @return string[]
     */
    public function getTags(): array
    {
        return $this->tags;
    }
}
