<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The cache-generation scheme GarrysMod::forget() uses to invalidate listings.
 *
 * This exists because the obvious implementation - delete the cache keys you know
 * about - is wrong in a way nothing catches. The object only knows the keys *this*
 * request read, so across a sequence of requests (the addon installer, the disk scan)
 * it clears nothing, and every step then reads a directory as it was up to cache_ttl
 * seconds earlier.
 *
 * That is not hypothetical: it made the installer rename a folder into place, read
 * the directory back, not see it, and report that the install had failed.
 *
 * The class itself needs a panel to construct, so the scheme is reproduced here
 * rather than imported. What is being pinned down is the property that matters: a
 * key minted after an invalidation must never collide with one minted before it,
 * even when the invalidating request shares nothing with the reading one.
 */
final class CacheGenerationTest extends TestCase
{
    /** @var array<string, mixed> stands in for the application cache */
    private array $cache = [];

    #[Test]
    public function a_key_is_stable_while_nothing_is_invalidated(): void
    {
        $this->assertSame(
            $this->key('server-a', 'garrysmod/addons'),
            $this->key('server-a', 'garrysmod/addons'),
        );
    }

    #[Test]
    public function invalidating_changes_the_key(): void
    {
        $before = $this->key('server-a', 'garrysmod/addons');

        $this->forget('server-a');

        $this->assertNotSame($before, $this->key('server-a', 'garrysmod/addons'));
    }

    #[Test]
    public function invalidation_survives_into_a_later_request(): void
    {
        // A request that reads the listing, then writes and invalidates.
        $stale = $this->key('server-a', 'garrysmod/addons');
        $this->forget('server-a');

        // A separate request: no shared object state, only the cache.
        $fresh = $this->freshRequest()->key('server-a', 'garrysmod/addons');

        // The old implementation failed exactly here - the second request had nothing
        // in memory to clear, so it went on reading the first request's cached entry.
        $this->assertNotSame($stale, $fresh);
    }

    #[Test]
    public function invalidating_one_server_leaves_another_alone(): void
    {
        $other = $this->key('server-b', 'garrysmod/addons');

        $this->forget('server-a');

        $this->assertSame($other, $this->freshRequest()->key('server-b', 'garrysmod/addons'));
    }

    #[Test]
    public function every_path_for_a_server_is_invalidated_at_once(): void
    {
        $addons = $this->key('server-a', 'garrysmod/addons');
        $disabled = $this->key('server-a', 'garrysmod/addons/__disabled');

        $this->forget('server-a');

        $next = $this->freshRequest();

        $this->assertNotSame($addons, $next->key('server-a', 'garrysmod/addons'));
        $this->assertNotSame($disabled, $next->key('server-a', 'garrysmod/addons/__disabled'));
    }

    #[Test]
    public function repeated_invalidation_keeps_moving_forward(): void
    {
        $seen = [];

        for ($i = 0; $i < 5; $i++) {
            $seen[] = $this->freshRequest()->key('server-a', 'garrysmod/addons');
            $this->forget('server-a');
        }

        $this->assertCount(5, array_unique($seen));
    }

    #[Test]
    public function a_lost_generation_counter_is_a_miss_rather_than_a_stale_hit(): void
    {
        $this->forget('server-a');
        $current = $this->key('server-a', 'garrysmod/addons');

        // The counter outlives any listing, but if the cache is flushed it goes too.
        unset($this->cache['gmod-toolkit:gen:server-a']);

        $reset = $this->freshRequest()->key('server-a', 'garrysmod/addons');

        // Back to generation zero. That is a cache miss and a fresh read, never a
        // stale entry served as current.
        $this->assertNotSame($current, $reset);
        $this->assertStringContainsString(':0:', $reset);
    }

    /* ------------------------------------------------------------------ */

    /** @var array<string, int> per-request memo, exactly as the real class keeps */
    private array $generations = [];

    private function key(string $uuid, string $path): string
    {
        return 'gmod-toolkit:dir:' . $this->generation($uuid) . ':' . $uuid . ':' . $path;
    }

    private function generation(string $uuid): int
    {
        return $this->generations[$uuid] ??= (int) ($this->cache['gmod-toolkit:gen:' . $uuid] ?? 0);
    }

    private function forget(string $uuid): void
    {
        $next = $this->generation($uuid) + 1;

        $this->generations[$uuid] = $next;
        $this->cache['gmod-toolkit:gen:' . $uuid] = $next;
    }

    /**
     * A new request against the same cache: the in-memory memo starts empty.
     */
    private function freshRequest(): self
    {
        $next = clone $this;
        $next->generations = [];

        return $next;
    }
}
