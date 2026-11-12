<?php

namespace TimberlandAIPageBuilder\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TimberlandAIPageBuilder\CheckQueue;

/**
 * VP4's queue: which published pages still owe their post-publish checks.
 *
 * The whole decision is `needs_check()`, which is why it is pure and tested here rather than
 * reached through `get_posts()` and post meta -- a test that had to stand up posts to get at it
 * would be exercising the stubs, not the rule.
 *
 * The rule exists because the queue is DERIVED. Nothing maintains a list of pending pages: a
 * maintained one leaks entries when a page is deleted between enqueue and run, and goes stale
 * when a page is edited after being enqueued but before being checked. Deriving it means an edit
 * re-queues the page by itself, with nothing having to notice the edit.
 */
class CheckQueueTest extends TestCase
{
    public function test_a_page_that_has_never_been_checked_is_pending(): void
    {
        $this->assertTrue(CheckQueue::needs_check(null, '2026-11-11 10:00:00'));
        $this->assertTrue(CheckQueue::needs_check([], '2026-11-11 10:00:00'));
    }

    public function test_a_page_checked_against_its_current_state_is_not_pending(): void
    {
        $report = ['ok' => true, 'checked_modified' => '2026-11-11 10:00:00'];

        $this->assertFalse(CheckQueue::needs_check($report, '2026-11-11 10:00:00'));
    }

    public function test_editing_a_checked_page_makes_it_pending_again(): void
    {
        // This is the re-check path, and it costs nothing: `post_modified_gmt` moves on an edit,
        // so the stored report stops matching without anything having to observe the edit.
        $report = ['ok' => true, 'checked_modified' => '2026-11-11 10:00:00'];

        $this->assertTrue(CheckQueue::needs_check($report, '2026-11-11 11:30:00'));
    }

    public function test_a_report_that_does_not_say_what_it_checked_is_treated_as_no_report(): void
    {
        // Trusting it would pin the page to a result that may describe markup it no longer has,
        // and there is no way to tell from the report itself which case this is.
        foreach ([[], ['ok' => true], ['ok' => true, 'checked_modified' => ''], ['ok' => true, 'checked_modified' => 123]] as $report) {
            $this->assertTrue(
                CheckQueue::needs_check($report, '2026-11-11 10:00:00'),
                'a report with no usable `checked_modified` must not satisfy the check'
            );
        }
    }

    public function test_a_failing_report_is_still_a_report(): void
    {
        // `ok: false` means the page was checked and found wanting -- re-running the same checks
        // against unchanged markup would return the same answer. Pending is about staleness, not
        // about the verdict; conflating them would loop forever on a page that genuinely fails.
        $report = ['ok' => false, 'checked_modified' => '2026-11-11 10:00:00'];

        $this->assertFalse(CheckQueue::needs_check($report, '2026-11-11 10:00:00'));
    }
}
