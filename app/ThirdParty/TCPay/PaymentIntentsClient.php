<?php
/**
 * TC Pay — Payment Intents client (shared by every PHP POS / commerce integration)
 *
 * Contract: tcpay-legacy-apps/api/docs/connector-payment-intents.md
 *
 * The rule every integration follows:
 *   - create() an intent, then decide what to do with an order ONLY from get().
 *   - Never mark an order paid from a browser redirect or from a webhook body.
 *     A verified webhook is a signal to call get(); get() is the truth.
 *
 * No dependencies beyond ext-curl and ext-json. PHP 8.0+.
 */
declare(strict_types=1);

namespace TCPay;

final class PaymentIntentsClient
{
    public const DEFAULT_BASE_URL = 'https://connector.tcpay.co.za/api/v1/connector';
    public const VERSION = '1.0.0';

    private const UUID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    private string $apiKey;
    private string $baseUrl;
    private int $timeout;

    public function __construct(string $apiKey, string $baseUrl = self::DEFAULT_BASE_URL, int $timeoutSeconds = 15)
    {
        $apiKey = trim($apiKey);
        if (self::environmentOf($apiKey) === null) {
            throw new PaymentIntentsException('API key must start with sandbox_tc_ or live_tc_', 0, 'INVALID_API_KEY_FORMAT');
        }
        $baseUrl = rtrim(trim($baseUrl), '/');
        if (!preg_match('#^https://#i', $baseUrl) && !preg_match('#^http://(127\.0\.0\.1|localhost)(:\d+)?/#i', $baseUrl . '/')) {
            throw new PaymentIntentsException('Base URL must use https (http is allowed only for localhost)', 0, 'INVALID_BASE_URL');
        }
        $this->apiKey = $apiKey;
        $this->baseUrl = $baseUrl;
        $this->timeout = max(5, $timeoutSeconds);
    }

    /** 'sandbox', 'live', or null if the key is not a TC Pay key. */
    public static function environmentOf(string $apiKey): ?string
    {
        if (str_starts_with($apiKey, 'sandbox_tc_')) return 'sandbox';
        if (str_starts_with($apiKey, 'live_tc_')) return 'live';
        return null;
    }

    /**
     * Create a PENDING intent. $idempotencyKey must be derived from your own
     * record (e.g. store + order + attempt + amount) so a retry after a timeout
     * returns the same intent instead of creating a second one.
     *
     * @param array{amount_cents:int, currency?:string, client_ref?:string, description?:string, metadata?:array, expires_in_minutes?:int} $params
     */
    public function create(array $params, string $idempotencyKey): array
    {
        if ($idempotencyKey === '' || strlen($idempotencyKey) > 128) {
            throw new PaymentIntentsException('Idempotency key must be 1-128 characters', 0, 'IDEMPOTENCY_KEY_REQUIRED');
        }
        return $this->request('POST', '/payment-intents', $params, $idempotencyKey);
    }

    public function get(string $id): array
    {
        return $this->request('GET', '/payment-intents/' . $this->checkId($id));
    }

    public function cancel(string $id, ?string $reason = null): array
    {
        return $this->request('POST', '/payment-intents/' . $this->checkId($id) . '/cancel', $reason === null ? [] : ['reason' => $reason]);
    }

    /** Sandbox keys only: play the payment rail. $outcome: success | failure | expire */
    public function simulate(string $id, string $outcome, ?string $reason = null): array
    {
        $body = ['outcome' => $outcome];
        if ($reason !== null) $body['reason'] = $reason;
        return $this->request('POST', '/payment-intents/' . $this->checkId($id) . '/simulate', $body);
    }

    /**
     * Verify a webhook delivery. Pass the RAW request body exactly as received.
     * Rejects bad signatures and timestamps outside +/- $toleranceSeconds.
     */
    public static function verifyWebhook(string $rawBody, ?string $signatureHeader, ?string $timestampHeader, string $secret, int $toleranceSeconds = 300): bool
    {
        if ($secret === '' || !$signatureHeader || !$timestampHeader || !ctype_digit($timestampHeader)) {
            return false;
        }
        $ageMs = (int) round(microtime(true) * 1000) - (int) $timestampHeader;
        if (abs($ageMs) > $toleranceSeconds * 1000) {
            return false;
        }
        $expected = 'sha256=' . hash_hmac('sha256', $timestampHeader . '.' . $rawBody, $secret);
        return hash_equals($expected, $signatureHeader);
    }

    private function checkId(string $id): string
    {
        if (!preg_match(self::UUID_RE, $id)) {
            throw new PaymentIntentsException('Invalid payment intent id', 404, 'NOT_FOUND');
        }
        return $id;
    }

    private function request(string $method, string $path, ?array $body = null, ?string $idempotencyKey = null): array
    {
        $headers = [
            'X-API-Key: ' . $this->apiKey,
            'Accept: application/json',
            'User-Agent: TCPay-PHP-PaymentIntents/' . self::VERSION,
        ];
        if ($idempotencyKey !== null) {
            $headers[] = 'X-Idempotency-Key: ' . $idempotencyKey;
        }
        $ch = curl_init($this->baseUrl . $path);
        $opts = [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
        ];
        if ($method !== 'GET') {
            $headers[] = 'Content-Type: application/json';
            $opts[CURLOPT_POSTFIELDS] = json_encode((object) ($body ?? []), JSON_UNESCAPED_SLASHES);
        }
        $opts[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $opts);

        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            // Network failure: the request may or may not have reached TC Pay.
            // Retrying with the SAME idempotency key is safe.
            throw new PaymentIntentsException('Could not reach TC Pay: ' . $curlError, 0, 'NETWORK_ERROR');
        }
        $data = json_decode((string) $raw, true);
        if (!is_array($data)) {
            throw new PaymentIntentsException('Unexpected response from TC Pay (HTTP ' . $status . ')', $status, 'BAD_RESPONSE');
        }
        if ($status >= 400) {
            throw new PaymentIntentsException((string) ($data['error'] ?? ('HTTP ' . $status)), $status, (string) ($data['code'] ?? 'HTTP_' . $status));
        }
        return $data;
    }
}

final class PaymentIntentsException extends \RuntimeException
{
    public function __construct(string $message, private int $httpStatus = 0, private string $errorCode = 'ERROR')
    {
        parent::__construct($message, $httpStatus);
    }

    public function getHttpStatus(): int { return $this->httpStatus; }
    public function getErrorCode(): string { return $this->errorCode; }

    /** True when retrying the same request with the same idempotency key may help. */
    public function isRetryable(): bool
    {
        return $this->errorCode === 'NETWORK_ERROR' || $this->errorCode === 'IDEMPOTENCY_IN_PROGRESS' || $this->httpStatus >= 500;
    }
}
