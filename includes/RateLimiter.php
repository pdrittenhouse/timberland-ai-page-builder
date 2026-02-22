<?php

namespace TimberlandAIPageBuilder;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Per-user rate limiting via WordPress transients.
 */
class RateLimiter
{
    /**
     * Check if a user is allowed to make a request.
     *
     * @return array{allowed: bool, message: string}
     */
    public function check(int $user_id): array
    {
        $settings = Plugin::get_settings();
        $hourly_limit = (int) ($settings['rate_limit_per_hour'] ?? 20);
        $daily_limit = (int) ($settings['rate_limit_per_day'] ?? 100);

        // Check hourly limit
        $hourly_key = "taipb_rate_hour_{$user_id}";
        $hourly_count = (int) get_transient($hourly_key);

        if ($hourly_count >= $hourly_limit) {
            return [
                'allowed' => false,
                'message' => "Hourly rate limit reached ({$hourly_limit}/hour). Please wait and try again.",
            ];
        }

        // Check daily limit
        $daily_key = "taipb_rate_day_{$user_id}";
        $daily_count = (int) get_transient($daily_key);

        if ($daily_count >= $daily_limit) {
            return [
                'allowed' => false,
                'message' => "Daily rate limit reached ({$daily_limit}/day). Please try again tomorrow.",
            ];
        }

        return [
            'allowed' => true,
            'message' => '',
        ];
    }

    /**
     * Record a usage event for the user.
     */
    public function record(int $user_id): void
    {
        // Increment hourly counter
        $hourly_key = "taipb_rate_hour_{$user_id}";
        $hourly_count = (int) get_transient($hourly_key);
        set_transient($hourly_key, $hourly_count + 1, HOUR_IN_SECONDS);

        // Increment daily counter
        $daily_key = "taipb_rate_day_{$user_id}";
        $daily_count = (int) get_transient($daily_key);
        set_transient($daily_key, $daily_count + 1, DAY_IN_SECONDS);
    }
}
