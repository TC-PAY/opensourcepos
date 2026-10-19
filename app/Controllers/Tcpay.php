<?php

namespace App\Controllers;

use App\Libraries\Sale_lib;
use App\Libraries\Tax_lib;
use App\Libraries\Tcpay_lib;
use App\Models\Tcpay_intent;
use CodeIgniter\HTTP\ResponseInterface;
use TCPay\PaymentIntentsException;

/**
 * TC Pay payment window in the register (app/Views/sales/tcpay_modal.php).
 * Needs the "sales" permission. Every action is limited to intents created by
 * the same register session.
 */
class Tcpay extends Secure_Controller
{
    private Sale_lib $saleLib;
    private Tcpay_lib $tcpayLib;

    public function __construct()
    {
        parent::__construct('sales');

        // Sale_lib refers to Tax_lib under its lower-case "app\Libraries" namespace, which the
        // autoloader cannot map to a file on a case-sensitive filesystem. Load it by its real
        // name first, as the Sales controller does.
        class_exists(Tax_lib::class);

        $this->saleLib  = new Sale_lib();
        $this->tcpayLib = new Tcpay_lib();
    }

    public function postCreate(): ResponseInterface
    {
        if (! $this->tcpayLib->isEnabled()) {
            return $this->fail(lang('Tcpay.error_disabled'));
        }

        if ($this->saleLib->get_mode() === 'return') {
            return $this->fail(lang('Tcpay.error_return_mode'));
        }

        $amount    = parse_decimals((string) $this->request->getPost('amount'));
        $requestId = (string) $this->request->getPost('request_id');

        if ($amount === false) {
            return $this->fail(lang('Tcpay.error_amount'));
        }

        try {
            $created = $this->tcpayLib->createIntent(
                (string) $amount,
                $this->saleLib->get_amount_due(),
                $requestId,
                (int) $this->employee->get_logged_in_employee_info()->person_id,
            );
        } catch (PaymentIntentsException $e) {
            log_message('error', 'TC Pay create failed: ' . $e->getErrorCode() . ' ' . $e->getMessage());

            return $this->fail($this->messageFor($e));
        }

        return $this->response->setJSON($this->present($created['intent']));
    }

    public function getStatus(string $intentId = ''): ResponseInterface
    {
        $intent = $this->tcpayLib->ownIntent($intentId);

        if ($intent === null) {
            return $this->fail(lang('Tcpay.error_not_found'), 404);
        }

        try {
            $intent = $this->tcpayLib->refresh($intent, $this->saleLib);
        } catch (PaymentIntentsException $e) {
            // Keep the window open on a temporary error; the next poll retries.
            log_message('error', 'TC Pay status failed: ' . $e->getErrorCode() . ' ' . $e->getMessage());

            return $this->response->setJSON($this->present($intent) + ['warning' => lang('Tcpay.error_unavailable')]);
        }

        return $this->response->setJSON($this->present($intent));
    }

    public function postSimulate(string $intentId = ''): ResponseInterface
    {
        $intent  = $this->tcpayLib->ownIntent($intentId);
        $outcome = (string) $this->request->getPost('outcome');

        if ($intent === null) {
            return $this->fail(lang('Tcpay.error_not_found'), 404);
        }

        if (! in_array($outcome, ['success', 'failure', 'expire'], true)) {
            return $this->fail(lang('Tcpay.error_simulate'));
        }

        try {
            $intent = $this->tcpayLib->simulate($intent, $outcome, $this->saleLib);
        } catch (PaymentIntentsException $e) {
            return $this->fail($this->messageFor($e));
        }

        return $this->response->setJSON($this->present($intent));
    }

    public function postCancel(string $intentId = ''): ResponseInterface
    {
        $intent = $this->tcpayLib->ownIntent($intentId);

        if ($intent === null) {
            return $this->fail(lang('Tcpay.error_not_found'), 404);
        }

        try {
            $intent = $this->tcpayLib->cancel($intent, $this->saleLib);
        } catch (PaymentIntentsException $e) {
            return $this->fail($this->messageFor($e));
        }

        return $this->response->setJSON($this->present($intent));
    }

    /**
     * Confirmed TC Pay payments this employee took that are not on any sale yet.
     */
    public function getUnattached(): ResponseInterface
    {
        $rows = model(Tcpay_intent::class)->getUnattachedConfirmed(
            (int) $this->employee->get_logged_in_employee_info()->person_id,
            Tcpay_lib::PAYMENT_PREFIX,
        );

        return $this->response->setJSON(array_map(fn (array $row): array => [
            'reference'  => $row['reference'],
            'amount'     => to_currency(bcdiv((string) $row['amount_cents'], '100', 2)),
            'created_at' => $row['created_at'],
            'in_cart'    => (int) $row['added_to_cart'] === 1 && hash_equals($row['session_token'], $this->tcpayLib->sessionToken()),
        ], $rows));
    }

    private function present(array $intent): array
    {
        return [
            'id'             => $intent['intent_id'],
            'reference'      => $intent['reference'],
            'status'         => $intent['status'],
            'amount'         => to_currency(bcdiv((string) $intent['amount_cents'], '100', 2)),
            'environment'    => $intent['environment'],
            'failure_reason' => $intent['failure_reason'],
            'final'          => in_array($intent['status'], Tcpay_intent::FINAL_STATES, true),
            'in_cart'        => (int) $intent['added_to_cart'] === 1,
        ];
    }

    private function fail(string $message, int $status = 400): ResponseInterface
    {
        return $this->response->setStatusCode($status)->setJSON(['error' => $message]);
    }

    private function messageFor(PaymentIntentsException $e): string
    {
        return match (true) {
            in_array($e->getErrorCode(), ['BAD_REQUEST', 'CURRENCY', 'AMOUNT', 'MISMATCH', 'SANDBOX_ONLY', 'CONFIG'], true) => $e->getMessage(),
            $e->getErrorCode() === 'LIVE_PAYMENTS_DISABLED'                                                                 => lang('Tcpay.error_live_disabled'),
            $e->getErrorCode() === 'INVALID_TRANSITION'                                                                     => lang('Tcpay.error_already_final'),
            $e->isRetryable()                                                                                               => lang('Tcpay.error_unavailable'),
            default                                                                                                         => lang('Tcpay.error_rejected'),
        };
    }
}
