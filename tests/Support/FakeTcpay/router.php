<?php

/**
 * A small stand-in for the TC Pay connector payment-intents API, served by
 * `php -S` during tests. State lives in a JSON file so it survives between
 * requests. Mirrors the real contract: sandbox keys only, idempotent create,
 * PENDING -> SUCCESS/FAILED/EXPIRED, 409 on a finished intent.
 *
 * Test-only control endpoint: POST /__test/tamper {id, field, value}.
 */
$stateFile = getenv('FAKE_TCPAY_STATE') ?: sys_get_temp_dir() . '/fake_tcpay_state.json';
$state     = is_file($stateFile) ? json_decode((string) file_get_contents($stateFile), true) : ['intents' => [], 'idem' => [], 'calls' => []];

$method = $_SERVER['REQUEST_METHOD'];
$path   = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$body   = json_decode((string) file_get_contents('php://input'), true) ?: [];
$key    = $_SERVER['HTTP_X_API_KEY'] ?? '';

$respond = static function (int $status, array $data) use (&$state, $stateFile): void {
    file_put_contents($stateFile, json_encode($state));
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data);
};

$state['calls'][] = $method . ' ' . $path;

if ($path === '/__test/tamper' && $method === 'POST') {
    $state['intents'][$body['id']][$body['field']] = $body['value'];
    $respond(200, ['ok' => true]);

    return;
}

$base = '/api/v1/connector/payment-intents';
if (! str_starts_with($path, $base)) {
    $respond(404, ['error' => 'Not found']);

    return;
}

if ($key === 'sandbox_tc_invalid') {
    $respond(401, ['error' => 'Invalid API key']);

    return;
}

if (! str_starts_with($key, 'sandbox_tc_')) {
    $respond(403, ['error' => 'Live payment intents are not available', 'code' => 'LIVE_PAYMENTS_DISABLED']);

    return;
}

$rest = substr($path, strlen($base));

if ($rest === '' && $method === 'POST') {
    $idem = $_SERVER['HTTP_X_IDEMPOTENCY_KEY'] ?? '';
    if ($idem === '') {
        $respond(400, ['error' => 'X-Idempotency-Key header is required', 'code' => 'IDEMPOTENCY_KEY_REQUIRED']);

        return;
    }
    if (isset($state['idem'][$idem])) {
        $respond(201, $state['intents'][$state['idem'][$idem]]);

        return;
    }
    if (strlen((string) ($body['client_ref'] ?? '')) > 15) {
        $respond(400, ['error' => 'client_ref must be at most 15 characters', 'code' => 'INVALID_CLIENT_REF']);

        return;
    }
    $id                    = sprintf('%08x-%04x-4%03x-8%03x-%012x', random_int(0, 0xFFFFFFFF), random_int(0, 0xFFFF), random_int(0, 0xFFF), random_int(0, 0xFFF), random_int(0, 0xFFFFFFFFFFFF));
    $state['intents'][$id] = [
        'id'             => $id,
        'object'         => 'payment_intent',
        'reference'      => 'PI-' . strtoupper(bin2hex(random_bytes(6))),
        'status'         => 'PENDING',
        'amount_cents'   => (int) $body['amount_cents'],
        'currency'       => $body['currency'] ?? 'ZAR',
        'client_ref'     => $body['client_ref'] ?? null,
        'failure_reason' => null,
        'payment_id'     => null,
        'environment'    => 'sandbox',
    ];
    $state['idem'][$idem] = $id;
    $respond(201, $state['intents'][$id]);

    return;
}

if (! preg_match('#^/([0-9a-f-]{36})(/(simulate|cancel))?$#', $rest, $m) || ! isset($state['intents'][$m[1]])) {
    $respond(404, ['error' => 'Payment intent not found', 'code' => 'NOT_FOUND']);

    return;
}

$intent = &$state['intents'][$m[1]];
$action = $m[3] ?? '';
$final  = in_array($intent['status'], ['SUCCESS', 'FAILED', 'EXPIRED'], true);

if ($action === '' && $method === 'GET') {
    $respond(200, $intent);

    return;
}

if ($final) {
    $respond(409, ['error' => 'Cannot move a ' . $intent['status'] . ' intent', 'code' => 'INVALID_TRANSITION']);

    return;
}

if ($action === 'simulate') {
    $outcome = $body['outcome'] ?? '';
    if ($outcome === 'success') {
        $intent['status']     = 'SUCCESS';
        $intent['payment_id'] = random_int(1, 999999);
    } elseif ($outcome === 'failure') {
        $intent['status']         = 'FAILED';
        $intent['failure_reason'] = $body['reason'] ?? 'simulated_decline';
    } else {
        $intent['status']         = 'EXPIRED';
        $intent['failure_reason'] = 'simulated_expiry';
    }
} elseif ($action === 'cancel') {
    $intent['status']         = 'FAILED';
    $intent['failure_reason'] = 'cancelled_by_merchant';
}

$respond(200, $intent);
