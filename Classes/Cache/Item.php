<?php

declare(strict_types=1);

namespace Sitegeist\StaleMate\Cache;

class Item
{
    /**
     * @var mixed
     */
    protected $value;

    /**
     * @var int|null
     */
    protected $staleTimestamp;

    /**
     * @param mixed $value
     * @param int|null $staleTimestamp
     */
    public function __construct($value, ?int $staleTimestamp)
    {
        $this->value = $value;
        $this->staleTimestamp = $staleTimestamp;
    }

    /**
     * @param mixed $value
     * @param int|null $gracePeriod
     * @return static
     */
    public static function createFromValueAndGracePeriod($value, ?int $gracePeriod = null): self
    {
        return new static($value, $gracePeriod ? time() + $gracePeriod : null);
    }

    /**
     * @return mixed
     */
    public function getValue()
    {
        return $this->value;
    }

    /**
     * @return int|null
     */
    public function getStaleTimestamp(): ?int
    {
        return $this->staleTimestamp;
    }

    public function isRefreshRequired(): bool
    {
        return time() > $this->staleTimestamp;
        ;
    }
}
