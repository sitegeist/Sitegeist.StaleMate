<?php
declare(strict_types=1);

namespace Sitegeist\StaleMate\Tests\Unit;

use Neos\Cache\Frontend\VariableFrontend;
use PHPUnit\Framework\TestCase;
use Sitegeist\StaleMate\Cache\ClosureCache;
use Sitegeist\StaleMate\Cache\Item;

class ClosureCacheTest extends TestCase
{
    /**
     * @var VariableFrontend
     */
    protected $mockCache;

    public function setUp(): void
    {
        $this->mockCache = $this->createMock(VariableFrontend::class);
    }

    /**
     * @test
     */
    public function inCaseOfEmptyCacheTheClosureIsCallesImmediately(): void
    {
        // the test subject
        $closureCache = new ClosureCache($this->mockCache, 600, 400, 60);

        // create a mock closure that expects to be called once
        $mockClass = $this->getMockBuilder(\stdClass::class)->addMethods(['calculateClosureValue'])->getMock();
        $mockClass->expects($this->once())->method('calculateClosureValue')->willReturn("result from closure");
        $mockClosure = \Closure::fromCallable([$mockClass, 'calculateClosureValue']);

        // cache has no item beforehand
        $this->mockCache->expects($this->once())->method('get')->with('nudelsuppe')->willReturn(false);
        // after synchronous calculation the item is stored with combined lifetime and closure lifetime
        $this->mockCache->expects($this->once())->method('set')->with('nudelsuppe', Item::createFromValueAndGracePeriod("result from closure", 400), [], 1000);

        // call cache resolve
        $result = $closureCache->resolve(
            'nudelsuppe',
            $mockClosure
        );

        $this->assertSame('result from closure', $result);
    }

    /**
     * @test
     */
    public function ifResultsAreInTheCacheTheClosureIsNotEvaluatedButTheResultIsReturned(): void
    {
        // the test subject
        $closureCache = new ClosureCache($this->mockCache, 600, 600, 60);

        // create a mock closure that expects not to be called
        $mockClass =  $this->getMockBuilder(\stdClass::class)->addMethods(['calculateClosureValue'])->getMock();
        $mockClass->expects($this->never())->method('calculateClosureValue')->willReturn("result from closure");
        $mockClosure = \Closure::fromCallable([$mockClass, 'calculateClosureValue']);

        // create a mock cache item
        $mockCacheItem = $this->createMock(Item::class);
        $mockCacheItem->expects($this->once())->method('isRefreshRequired')->willReturn(false);
        $mockCacheItem->expects($this->once())->method('getValue')->willReturn("result from cache");

        $this->mockCache->expects($this->once())->method('get')->with('nudelsuppe')->willReturn( $mockCacheItem);
        $result = $closureCache->resolve(
            'nudelsuppe',
            $mockClosure
        );
        $this->assertSame("result from cache", $result);
    }

    /**
     * @test
     */
    public function ifValueIsStaleItIsReturnedButScheduledForRefresh(): void
    {
        // the test subject
        $closureCache = new ClosureCache($this->mockCache, 600, 400, 60);

        // create a mock closure that expects not to be called
        $mockClass =  $this->getMockBuilder(\stdClass::class)->addMethods(['calculateClosureValue'])->getMock();
        $mockClass->expects($this->once())->method('calculateClosureValue')->willReturn("result from closure");
        $mockClosure = \Closure::fromCallable([$mockClass, 'calculateClosureValue']);

        // create a mock cache item
        $mockCacheItem = $this->createMock(Item::class);
        $mockCacheItem->expects($this->once())->method('isRefreshRequired')->willReturn(true);
        $mockCacheItem->expects($this->once())->method('getValue')->willReturn("result from cache");

        // the cache will set an
        $this->mockCache->expects($this->once())->method('get')->with('nudelsuppe')->willReturn( $mockCacheItem);
        $this->mockCache->expects($this->once())->method('has')->with('nudelsuppe' . ClosureCache::LOCKED)->willReturn( false);
        $this->mockCache->expects($this->exactly(2))->method('set')->withConsecutive(
            ['nudelsuppe' . ClosureCache::LOCKED, true],
            ['nudelsuppe', Item::createFromValueAndGracePeriod("result from closure", 400), [], 1000]
        );

        $result = $closureCache->resolve(
            'nudelsuppe',
            $mockClosure
        );
        $this->assertSame("result from cache", $result);

        $closureCache->shutdownObject();
    }

    /**
     * @test
     */
    public function ifAnLockIsPresentForAnEntryIdentifierTheUpdateClosureIsNotCalled(): void
    {
        // the test subject
        $closureCache = new ClosureCache($this->mockCache, 600, 600, 60);

        // create a mock closure that expects not to be called
        $mockClass =  $this->getMockBuilder(\stdClass::class)->addMethods(['calculateClosureValue'])->getMock();
        $mockClass->expects($this->never())->method('calculateClosureValue')->willReturn("result from closure");
        $mockClosure = \Closure::fromCallable([$mockClass, 'calculateClosureValue']);

        // create a mock cache item
        $mockCacheItem = $this->createMock(Item::class);
        $mockCacheItem->expects($this->once())->method('isRefreshRequired')->willReturn(true);
        $mockCacheItem->expects($this->once())->method('getValue')->willReturn("result from cache");

        // with a update lock the caches are not written
        $this->mockCache->expects($this->once())->method('get')->willReturn( $mockCacheItem);
        $this->mockCache->expects($this->once())->method('has')->with('nudelsuppe' . ClosureCache::LOCKED)->willReturn( true);
        $this->mockCache->expects($this->never())->method('set');

        $result = $closureCache->resolve(
            'nudelsuppe',
            $mockClosure
        );
        $this->assertSame("result from cache", $result);

        $closureCache->shutdownObject();
    }

    /**
     * @test
     */
    public function exceptionsAreThrownWhenClosureIsCalledSychronously(): void
    {
        // the test subject
        $closureCache = new ClosureCache($this->mockCache, 600, 600, 60);

        // the cache returns no results and thus the closure has to be calles syncronously
        $this->mockCache->expects($this->once())->method('get')->with('nudelsuppe')->willReturn( false);
        // since the closure threw an exception nothing is written
        $this->mockCache->expects($this->never())->method('set');
        $this->mockCache->expects($this->never())->method('has')->with('nudelsuppe' . ClosureCache::LOCKED);

        $this->expectException(\Exception::class);

        $result = $closureCache->resolve(
            'nudelsuppe',
            function() {
                throw new \Exception();
            }
        );

        $this->assertEquals("result from cache", $result);
    }

    /**
     * @test
     */
    public function exceptionsAreLoggedWhenClosureIsCalledAsychronously(): void
    {
        // the test subject
        $closureCache = $this->getMockBuilder(ClosureCache::class)->onlyMethods(['logException'])->setConstructorArgs([$this->mockCache, 600, 600, 60])->getMock();

        $exception = new \Exception();

        // create a mock cache item
        $mockCacheItem = $this->createMock(Item::class);
        $mockCacheItem->expects($this->once())->method('isRefreshRequired')->willReturn(true);
        $mockCacheItem->expects($this->once())->method('getValue')->willReturn("result from cache");

        // stale cache item but no update lock preset will create a lock but no new cache item since exception was thrown
        $this->mockCache->expects($this->once())->method('get')->with('nudelsuppe')->willReturn( $mockCacheItem);
        $this->mockCache->expects($this->once())->method('has')->with('nudelsuppe' . ClosureCache::LOCKED)->willReturn( false);
        $this->mockCache->expects($this->once())->method('set')->with('nudelsuppe' . ClosureCache::LOCKED, true);

        // but the exception is logged
        $closureCache->expects($this->once())->method('logException')->with('nudelsuppe', $exception);

        $result = $closureCache->resolve(
            'nudelsuppe',
            function() {
                throw new \Exception();
            }
        );

        $this->assertEquals("result from cache", $result);
        $closureCache->shutdownObject();
    }
}
