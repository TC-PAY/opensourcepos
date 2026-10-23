# TC Pay at the register

This fork adds **TC Pay** as a payment type in the OSPOS register. TC Pay
(TC Pay (Pty) Ltd, South Africa) is the authority for the payment: the till
never decides on its own that a TC Pay payment was made.

## How a TC Pay payment works

1. The cashier rings up the sale, chooses **TC Pay** as the payment type and
   clicks **Add Payment** (the amount defaults to the amount due; a smaller
   amount gives a split tender).
2. The TC Pay payment window opens with a **TC Pay reference**. The till asks
   TC Pay for the status every few seconds.
3. When TC Pay reports the payment as paid, for exactly that amount and
   reference, the till adds a `TC Pay:<reference>` payment line to the sale.
   The cashier completes the sale as usual; the reference prints on the receipt.

In **sandbox** mode the window has *Simulate payment / decline / expiry*
buttons so the whole flow can be tested without money moving.

## Safeguards

- A TC Pay line is added to the cart only by the server, only after TC Pay's
  status lookup says SUCCESS for the same amount, currency (ZAR) and reference.
  The normal "add payment" path refuses TC Pay types, so a line cannot be typed in.
- When a sale is completed, every TC Pay line is checked again: confirmed,
  created by this register session, same amount, not already on another sale.
- A confirmed TC Pay payment is money received, so it cannot be deleted from the
  cart, and a sale holding one cannot be cancelled or suspended. On a saved
  sale, TC Pay payment rows are read-only in the edit screen.
- Every TC Pay action is limited to the register session that started it.
- If a confirmed payment never made it onto a sale (for example the browser was
  closed), the register shows a warning listing it.
- API keys are stored encrypted with the install's encryption key and are never
  shown again after saving.
- ZAR only; not available in return mode. Live mode is unavailable until TC Pay
  connects a payment rail.

## Setup

**Office → Configuration → System → Integrations → TC Pay**: choose the
environment, paste the sandbox (`sandbox_tc_…`) or live (`live_tc_…`) API key,
**Test connection**, tick **Accept TC Pay at the register**, and submit. The
store currency code must be `ZAR`.

## Code

| Path | Purpose |
|---|---|
| `app/Libraries/Tcpay_lib.php` | The rules: create, refresh, simulate, cancel, verify at completion |
| `app/Controllers/Tcpay.php` | Payment window endpoints (needs the `sales` permission) |
| `app/Models/Tcpay_intent.php` | `ospos_tcpay_intents`: one row per TC Pay payment intent |
| `app/Views/sales/tcpay_modal.php` | The payment window |
| `app/Views/configs/tcpay_config.php` | Settings form |
| `app/ThirdParty/TCPay/PaymentIntentsClient.php` | Shared TC Pay PHP client |
| `app/Database/Migrations/20261019000000_TcpayPaymentTender.php` | Table and settings |
| `tools/tcpay/install-test-till.sh` | Builds and installs a sandbox test till on the TC Pay server |

Changes to existing files are small and marked by `Tcpay_lib` calls in
`Sales.php` (guards), `Config.php` (settings), `register.php` (opens the
window) and `sales/form.php` (read-only TC Pay rows).

## Tests

`composer test`. TC Pay tests run against a local fake TC Pay API
(`tests/Support/FakeTcpay/router.php`) over real HTTP:
`TcpayControllerTest` plus TC Pay cases in `SalesControllerTest` and `ConfigTest`.

The upstream OSPOS licence and the copyright footer on every page are unchanged.
