<?php

namespace Levi\Agent\AI\Concerns;

use WP_Error;

/**
 * Provides retry-with-backoff for AI provider API calls.
 * Retries on transient errors (429, 502, 503, timeouts, rate limits).
 */
trait RetriableApiCall {

    private function executeWithRetry(callable $apiCall, string $providerName, int $maxRetries = 3): array|WP_Error {
        $lastError = null;
        $backoffSeconds = [1, 2, 4];

        for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
            if ($attempt > 0) {
                $delay = $backoffSeconds[$attempt - 1] ?? 4;
                error_log(sprintf('Levi %s: retry %d/%d after %ds', $providerName, $attempt, $maxRetries, $delay));
                sleep($delay);
            }

            $result = $apiCall();

            if (!is_wp_error($result)) {
                return $result;
            }

            $lastError = $result;
            $errData = $result->get_error_data();
            $httpStatus = is_array($errData) ? (int) ($errData['status'] ?? 0) : 0;
            $errMsg = mb_strtolower($result->get_error_message());

            $isRetriable = in_array($httpStatus, [429, 502, 503], true)
                || str_contains($errMsg, 'timed out')
                || str_contains($errMsg, 'curl error 28')
                || str_contains($errMsg, 'rate limit')
                || str_contains($errMsg, 'overloaded')
                || str_contains($errMsg, 'server error');

            if (!$isRetriable) {
                return $lastError;
            }
        }

        return $lastError;
    }
}
