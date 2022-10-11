# Sitegeist.StaleMate 

Cache with asynchronous updates that returns stale results in the meantime.

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
    /**
     * @var StaleMateCache
     * @Flow\Inject
     */
    protected $staleMateCache;
```

The cache is then called via the `get` mathod with an `identifier` and a`closure`.
Please note that the closure cannot have arguments but instead may `use` variables
from the context the method is called from.

```php
    $result = $this->staleMateCache->get(
        $cacheId, // identifier used to store the result, has to be unique
        function () use ($suffThatIsNeeded) {
            $response = ... some expensive operation ...
            return $response;
        }, // closure to generate the result if no result is in the cache
        86400, // lifetime of the cached result until a refresh is needed
        43200, // period after lifetime where an update is performed async and the stale result is used
        ['someTag'] // tags for the cache item  
    );
```

## Installation

Sitegeist.StaleMate is available via packagist. Just run `composer require sitegeist/stalemate` to install it. We use semantic-versioning so every breaking change will increase the major-version number.

## Contribution

We will gladly accept contributions. Please send us pull requests.
