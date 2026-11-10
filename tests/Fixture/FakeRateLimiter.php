<?php
declare( strict_types=1 );

namespace TimberlandAIPageBuilder\Tests\Fixture;

use TimberlandAIPageBuilder\RateLimiter;

/**
 * A `RateLimiter` that always allows and records nothing.
 *
 * The real one reads and writes WordPress transients per user. The eval suite is about what the
 * pipeline does with a model's response, so the limiter is held constant rather than exercised —
 * a rate-limit test belongs with the limiter, not here.
 */
final class FakeRateLimiter extends RateLimiter {

    /** @var int how many times record() was called, so a test can assert usage IS recorded */
    public int $recorded = 0;

    /**
     * @return array{allowed: bool, remaining: int, reset_at: int}
     */
    public function check( int $user_id ): array {
        return array( 'allowed' => true, 'remaining' => 99, 'reset_at' => 0 );
    }

    public function record( int $user_id ): void {
        $this->recorded++;
    }
}
