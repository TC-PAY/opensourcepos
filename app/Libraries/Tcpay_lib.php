<?php

namespace App\Libraries;

use App\Models\Tcpay_intent;
use CodeIgniter\Session\Session;
use Config\OSPOS;
use Config\Services;
use TCPay\PaymentIntentsClient;
use TCPay\PaymentIntentsException;
use Throwable;

/**
 * TC Pay as a payment type in the register.
 *
 * TC Pay is the authority for the payment. The till creates a payment intent,
 * then learns the outcome only by asking TC Pay (GET). A TC Pay line is added
 * to the cart by this library and nowhere else, and only after TC Pay says
 * SUCCESS for the exact amount and reference this till created.
 */
class Tcpay_lib
{
    public const PAYMENT_TYPE       = 'TC Pay';
    public const PAYMENT_PREFIX     = 'TC Pay:';
    private const SESSION_TOKEN_KEY = 'tcpay_session_token';

    private array $config;
    private Session $session;
    private Tcpay_intent $intents;
    private ?PaymentIntentsClient $clientOverride;

    public function __construct(?PaymentIntentsClient $client = null, ?Tcpay_intent $intents = null)
    {
        $this->config         = config(OSPOS::class)->settings;
        $this->session        = session();
        $this->intents        = $intents ?? model(Tcpay_intent::class);
        $this->clientOverride = $client;
    }

    public static function isTcpayPaymentType(?string $paymentType): bool
    {
        return $paymentType !== null
            && ($paymentType === self::PAYMENT_TYPE || str_starts_with($paymentType, self::PAYMENT_PREFIX));
    }

    public function isEnabled(): bool
    {
        return ($this->config['tcpay_enabled'] ?? '0') === '1' && $this->apiKey() !== '';
    }

    public function environment(): string
    {
        return ($this->config['tcpay_environment'] ?? 'sandbox') === 'live' ? 'live' : 'sandbox';
    }

    public function testButtonsEnabled(): bool
    {
        return $this->environment() === 'sandbox' && ($this->config['tcpay_test_buttons'] ?? '1') === '1';
    }

    public function client(): PaymentIntentsClient
    {
        if ($this->clientOverride !== null) {
            return $this->clientOverride;
        }

        $key = $this->apiKey();
        if (PaymentIntentsClient::environmentOf($key) !== $this->environment()) {
            throw new PaymentIntentsException('The ' . $this->environment() . ' API key is missing or is for the wrong environment', 0, 'CONFIG');
        }

        $url = (string) ($this->config['tcpay_api_url'] ?? '') ?: PaymentIntentsClient::DEFAULT_BASE_URL;

        return new PaymentIntentsClient($key, $url);
    }

    /**
     * A random token for this register session. Intents are bound to it, so
     * one browser can never use or watch another browser's TC Pay payment.
     * Kept in session data, so it survives session ID regeneration.
     */
    public function sessionToken(): string
    {
        $token = $this->session->get(self::SESSION_TOKEN_KEY);

        if (! is_string($token) || ! preg_match('/^[a-f0-9]{32}$/', $token)) {
            $token = bin2hex(random_bytes(16));
            $this->session->set(self::SESSION_TOKEN_KEY, $token);
        }

        return $token;
    }

    /**
     * @return array{intent: array, remote: array}
     */
    public function createIntent(string $amount, string $amountDue, string $requestId, int $employeeId): array
    {
        if (! preg_match('/^[a-f0-9]{32}$/', $requestId)) {
            throw new PaymentIntentsException(lang('Tcpay.error_request'), 0, 'BAD_REQUEST');
        }

        $currency = strtoupper((string) ($this->config['currency_code'] ?? ''));
        if ($currency !== 'ZAR') {
            throw new PaymentIntentsException(lang('Tcpay.error_currency'), 0, 'CURRENCY');
        }

        $amountCents = self::toCents($amount);
        if ($amountCents === null || $amountCents <= 0) {
            throw new PaymentIntentsException(lang('Tcpay.error_amount'), 0, 'AMOUNT');
        }

        $dueCents = self::toCents($amountDue);
        if ($dueCents === null || $amountCents > $dueCents) {
            throw new PaymentIntentsException(lang('Tcpay.error_amount_exceeds_due'), 0, 'AMOUNT');
        }

        $sessionToken = $this->sessionToken();
        $clientRef    = 'OS-' . strtoupper(substr(hash('sha256', $sessionToken . $requestId), 0, 12));
        $expiry       = max(5, min(1440, (int) ($this->config['tcpay_expiry_minutes'] ?? 15)));
        $storeId      = substr(hash('sha256', (string) ($this->config['company'] ?? '') . base_url()), 0, 10);

        $remote = $this->client()->create([
            'amount_cents'       => $amountCents,
            'currency'           => 'ZAR',
            'client_ref'         => $clientRef,
            'description'        => mb_substr(lang('Tcpay.intent_description', [(string) ($this->config['company'] ?? 'OSPOS')]), 0, 255),
            'metadata'           => ['platform' => 'ospos', 'store' => $storeId, 'employee_id' => $employeeId],
            'expires_in_minutes' => $expiry,
        ], 'ospos-' . $storeId . '-' . $requestId);

        if (($remote['client_ref'] ?? '') !== $clientRef || (int) ($remote['amount_cents'] ?? -1) !== $amountCents) {
            throw new PaymentIntentsException(lang('Tcpay.error_mismatch'), 0, 'MISMATCH');
        }

        $this->intents->addIntent([
            'intent_id'     => (string) $remote['id'],
            'reference'     => (string) $remote['reference'],
            'client_ref'    => $clientRef,
            'environment'   => $this->environment(),
            'amount_cents'  => $amountCents,
            'status'        => (string) $remote['status'],
            'session_token' => $sessionToken,
            'employee_id'   => $employeeId,
        ]);

        return ['intent' => $this->intents->getIntent((string) $remote['id']), 'remote' => $remote];
    }

    /**
     * The intent, only if this register session created it.
     */
    public function ownIntent(string $intentId): ?array
    {
        $intent = $this->intents->getIntent($intentId);

        return ($intent !== null && hash_equals($intent['session_token'], $this->sessionToken())) ? $intent : null;
    }

    /**
     * Asks TC Pay for the intent and applies a final result locally, once.
     * On SUCCESS the payment is added to this session's cart, once.
     */
    public function refresh(array $intent, Sale_lib $saleLib): array
    {
        $remote = $this->client()->get($intent['intent_id']);

        return $this->apply($intent, $remote, $saleLib);
    }

    public function simulate(array $intent, string $outcome, Sale_lib $saleLib): array
    {
        if (! $this->testButtonsEnabled() || $intent['environment'] !== 'sandbox') {
            throw new PaymentIntentsException(lang('Tcpay.error_simulate'), 0, 'SANDBOX_ONLY');
        }

        $this->client()->simulate($intent['intent_id'], $outcome);

        return $this->refresh($intent, $saleLib);
    }

    public function cancel(array $intent, Sale_lib $saleLib): array
    {
        if (! in_array($intent['status'], Tcpay_intent::FINAL_STATES, true)) {
            try {
                $this->client()->cancel($intent['intent_id'], 'cancelled at the register');
            } catch (PaymentIntentsException $e) {
                // 409: already final at TC Pay (for example, the customer just paid). Read it.
                if ($e->getHttpStatus() !== 409) {
                    throw $e;
                }
            }
        }

        return $this->refresh($intent, $saleLib);
    }

    private function apply(array $intent, array $remote, Sale_lib $saleLib): array
    {
        $status = (string) ($remote['status'] ?? '');

        if (! in_array($status, Tcpay_intent::FINAL_STATES, true)) {
            return $this->intents->getIntent($intent['intent_id']) ?? $intent;
        }

        $matches = ($remote['id'] ?? '') === $intent['intent_id']
            && (int) ($remote['amount_cents'] ?? -1) === (int) $intent['amount_cents']
            && ($remote['client_ref'] ?? '') === $intent['client_ref']
            && ($remote['reference'] ?? '') === $intent['reference']
            && ($remote['currency'] ?? '') === 'ZAR';

        if (! $matches) {
            log_message('critical', 'TC Pay: intent ' . $intent['intent_id'] . ' does not match what this till created; not applied');

            return $intent;
        }

        $this->intents->finalise(
            $intent['intent_id'],
            $status,
            isset($remote['payment_id']) ? (int) $remote['payment_id'] : null,
            isset($remote['failure_reason']) ? (string) $remote['failure_reason'] : null,
        );

        $current = $this->intents->getIntent($intent['intent_id']);

        if ($current['status'] === 'SUCCESS' && $this->intents->claimForCart($current['intent_id'], $this->sessionToken())) {
            $saleLib->addPayment(
                self::PAYMENT_PREFIX . $current['reference'],
                bcdiv((string) $current['amount_cents'], '100', 2),
                $current['reference'],
            );
            $current = $this->intents->getIntent($intent['intent_id']);
        }

        return $current;
    }

    /**
     * Checked when a sale is completed. Every TC Pay line in the cart must be a
     * confirmed intent this session claimed, for the same amount, not already
     * recorded on another sale. Returns an error message, or null when valid.
     */
    public function verifyCartPayments(array $payments): ?string
    {
        $db = db_connect();

        foreach ($payments as $key => $payment) {
            $type = (string) ($payment['payment_type'] ?? $key);

            if (! self::isTcpayPaymentType($type)) {
                continue;
            }

            $reference = substr($type, strlen(self::PAYMENT_PREFIX));
            $intent    = $reference !== '' ? $this->intents->getByReference($reference) : null;

            $valid = $intent !== null
                && str_starts_with($type, self::PAYMENT_PREFIX)
                && $intent['status'] === 'SUCCESS'
                && (int) $intent['added_to_cart'] === 1
                && hash_equals($intent['session_token'], $this->sessionToken())
                && self::toCents((string) $payment['payment_amount']) === (int) $intent['amount_cents'];

            if (! $valid) {
                log_message('critical', 'TC Pay: rejected unverified payment line ' . $type);

                return lang('Tcpay.error_unverified_payment', [$reference]);
            }

            $alreadyUsed = $db->table('sales_payments')->where('payment_type', $type)->countAllResults();
            if ($alreadyUsed > 0) {
                return lang('Tcpay.error_payment_already_used', [$reference]);
            }
        }

        return null;
    }

    public static function cartHasTcpayPayment(array $payments): bool
    {
        foreach ($payments as $key => $payment) {
            if (self::isTcpayPaymentType((string) ($payment['payment_type'] ?? $key))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whole cents, or null if the amount has more than two decimals or is not a number.
     */
    public static function toCents(string $amount): ?int
    {
        if (! preg_match('/^-?\d+(\.\d+)?$/', $amount)) {
            return null;
        }

        $cents = bcmul($amount, '100', 4);
        if (bccomp($cents, bcadd($cents, '0', 0), 4) !== 0) {
            return null;
        }

        return (int) bcadd($cents, '0', 0);
    }

    public function encryptSecret(string $plain): string
    {
        helper('security');
        checkEncryption();

        return Services::encrypter()->encrypt($plain);
    }

    private function apiKey(): string
    {
        $stored = (string) ($this->config[$this->environment() === 'live' ? 'tcpay_live_key' : 'tcpay_sandbox_key'] ?? '');
        if ($stored === '') {
            return '';
        }

        try {
            return (string) Services::encrypter()->decrypt($stored);
        } catch (Throwable) {
            log_message('error', 'TC Pay: stored API key could not be decrypted; re-enter it in Configuration > Integrations');

            return '';
        }
    }
}
