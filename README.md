# Sitegeist.StaleMate
## Varnish like cache for Neos.Flow with asynchronous updates that returns stale results in the meantime

This package implements cache that will return stale values for a configurable time while the cache values are updated 
asynchronously. Such behavior is well known from the varnish cache but not common in php. 

The core concept is that the staleMateCache gets an `identifier` and `closure` for generating the required information
if it cannot be found in the cache. The closure is called immediately if no cached result is found. If a result is found 
the cached result is returned.

In case the cached item is older than the intended lifetime the stale item is still returned immediately but the closure 
is scheduled for evaluating during the shutdown of the flow application and thus after the response has been sent to the 
user.

### Authors & Sponsors

* Melanie Wüst - wuest@sitegeist.de
* Martin Ficzel - ficzel@sitegeist.de

*The development and the public-releases of this package is generously sponsored by our employer https://www.sitegeist.de.*

## Usage

The StaleMate cache is injected from flow.

```php

    use \Sitegeist\StaleMate\Cache\ClosureCache as StaleMateCache;     
    
    /**
     * @var VariableFrontend
     */
    protected $cache;
    
    /**
     * @var StaleMateCache
     */
    protected $staleMateCache;

    /**
     * @param VariableFrontend $cache
     * @return void
     */
    public function injectCache(VariableFrontend $cache): void
    {
        $this->cache = $cache;
    }
    
    public function initializeObject()
    {
        $staleMateCache = new \Sitegeist\StaleMate\Cache\ClosureCache(
            $this->cache, // the variable frontend to cache the data in
            8600, // lifetime for the items
            4300, // gracePeriod where items are updated asynchronous
            60 // lockPeriod for asynchronous updates 
        );
    }
```

The cache is then called via the `resolve` method with an `identifier` and a`closure`.
Please note that the closure cannot have arguments but instead may `use` variables
from the context the method is called from.

```php
    $result = $this->staleMateCache->resolve(
        $cacheId, // identifier used to store the result, has to be unique
        function () use ($stuffThatIsNeeded) {
            $response = ... some expensive operation ...
            return $response;
        }, // closure to generate the result if no result is in the cache
        ['someTag'], // tags for the cache item  
        86400, // lifetime of the cached result until a refresh is needed
        43200, // gracePeriod after lifetime where an update is performed async and the stale result is used
        60 // lockPeriod for asynchronous updates 
    );
```

## Installation

Sitegeist.StaleMate is available via packagist. Just run `composer require sitegeist/stalemate` to install it. We use semantic-versioning so every breaking change will increase the major-version number.

## Comparison to Symfony Cache Contracts

The approach implemented here is somewhat similar to the [symfony cache contracts](https://symfony.com/doc/current/components/cache.html#cache-contracts) 
but deviates in a specific way. Symfony Cache Contracts will roll a dice to upgrade cache entries before they expire to prevent 
mass invalidation and recomputation. Stalemate on the other hand will return the cached item and recompute the value after the response was sent to the user.

## Contribution

We will gladly accept contributions. Please send us pull requests.
