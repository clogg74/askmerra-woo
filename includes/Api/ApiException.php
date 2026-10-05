<?php

declare(strict_types=1);

namespace AskMerra\WooCommerce\Api;

/**
 * A failed AskMerra API call. AskMerra answers errors as
 * {"error": {"code", "message", "details"?, "requestId"}} - kept here so the sync can tell an
 * invalid key from a rate limit from a passing outage.
 */
final class ApiException extends \RuntimeException
{
    public function __construct(
        string $message,
        private readonly int $httpStatus,
        private readonly string $errorCode,
        private readonly string $requestId,
        private readonly int $retryAfter = 0,
        private readonly array $details = []
    ) {
        parent::__construct($message, $httpStatus);
    }

    /** No answer at all: DNS, connection, timeout. */
    public static function transport(string $reason, string $requestId): self
    {
        return new self(
            sprintf(
                /* translators: 1: why the call failed, 2: request id for AskMerra support */
                __('AskMerra could not be reached: %1$s (request %2$s)', 'askmerra-for-woocommerce'),
                $reason,
                $requestId
            ),
            0,
            'transport',
            $requestId
        );
    }

    public static function fromResponse(int $status, mixed $decodedBody, array $headers, string $requestId): self
    {
        $error = is_array($decodedBody) && is_array($decodedBody['error'] ?? null) ? $decodedBody['error'] : [];
        $code = (string) ($error['code'] ?? 'http_' . $status);
        $message = (string) ($error['message'] ?? 'HTTP ' . $status);
        $requestId = (string) ($error['requestId'] ?? $requestId);
        $details = is_array($error['details'] ?? null) ? $error['details'] : [];

        $retryAfter = 0;

        foreach ($headers as $name => $value) {
            if (strtolower((string) $name) === 'retry-after') {
                $retryAfter = self::parseRetryAfter((string) (is_array($value) ? reset($value) : $value));
            }
        }

        $retryAfter = $retryAfter ?: (int) ($details['retryAfterSec'] ?? 0);

        return new self(
            sprintf(
                /* translators: 1: HTTP status, 2: AskMerra error code, 3: AskMerra error message, 4: request id */
                __('AskMerra API error %1$d %2$s: %3$s (request %4$s)', 'askmerra-for-woocommerce'),
                $status,
                $code,
                $message,
                $requestId
            ),
            $status,
            $code,
            $requestId,
            $retryAfter,
            $details
        );
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getRequestId(): string
    {
        return $this->requestId;
    }

    public function getDetails(): array
    {
        return $this->details;
    }

    /** The key is wrong or revoked, or the AskMerra shop is suspended: retrying will not help. */
    public function isAuthError(): bool
    {
        return in_array($this->httpStatus, [401, 403, 404], true);
    }

    public function isRateLimited(): bool
    {
        return $this->httpStatus === 429;
    }

    public function isPayloadTooLarge(): bool
    {
        return $this->httpStatus === 413;
    }

    /** Worth trying again later: no answer, a rate limit or a server error. */
    public function isRetryable(): bool
    {
        return $this->httpStatus === 0
            || $this->httpStatus === 408
            || $this->httpStatus === 429
            || $this->httpStatus >= 500;
    }

    /** Seconds to wait before the next call, as AskMerra asked; a minute when it did not say. */
    public function getRetryAfter(): int
    {
        return $this->retryAfter > 0 ? $this->retryAfter : 60;
    }

    /** Retry-After is seconds or an HTTP date. */
    private static function parseRetryAfter(string $value): int
    {
        $value = trim($value);

        if ($value === '') {
            return 0;
        }

        if (ctype_digit($value)) {
            return (int) $value;
        }

        $time = strtotime($value);

        return $time === false ? 0 : max(0, $time - time());
    }
}
