<?php

namespace Tests\Support;

use App\Models\Appconfig;
use Config\OSPOS;
use Config\Services;
use RuntimeException;

/**
 * Starts the fake TC Pay API (tests/Support/FakeTcpay/router.php) on a local
 * port and points OSPOS's TC Pay settings at it, in sandbox mode.
 */
trait FakeTcpayServerTrait
{
    private static $fakeTcpayProcess;
    private static int $fakeTcpayPort     = 0;
    private static string $fakeTcpayState = '';

    protected static function startFakeTcpay(): void
    {
        if (self::$fakeTcpayProcess !== null) {
            return;
        }

        $socket              = stream_socket_server('tcp://127.0.0.1:0');
        self::$fakeTcpayPort = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);

        self::$fakeTcpayState = tempnam(sys_get_temp_dir(), 'fake_tcpay_');
        file_put_contents(self::$fakeTcpayState, json_encode(['intents' => [], 'idem' => [], 'calls' => []]));

        $router                 = __DIR__ . '/FakeTcpay/router.php';
        self::$fakeTcpayProcess = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . self::$fakeTcpayPort, $router],
            [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            null,
            ['FAKE_TCPAY_STATE' => self::$fakeTcpayState],
        );

        for ($i = 0; $i < 50; $i++) {
            $probe = @fsockopen('127.0.0.1', self::$fakeTcpayPort, $errno, $errstr, 0.1);
            if ($probe) {
                fclose($probe);

                return;
            }
            usleep(100000);
        }

        throw new RuntimeException('Fake TC Pay server did not start');
    }

    protected static function stopFakeTcpay(): void
    {
        if (self::$fakeTcpayProcess !== null) {
            proc_terminate(self::$fakeTcpayProcess);
            proc_close(self::$fakeTcpayProcess);
            self::$fakeTcpayProcess = null;
        }

        if (self::$fakeTcpayState !== '' && is_file(self::$fakeTcpayState)) {
            unlink(self::$fakeTcpayState);
        }
    }

    protected function fakeTcpayUrl(): string
    {
        return 'http://127.0.0.1:' . self::$fakeTcpayPort . '/api/v1/connector';
    }

    protected function fakeTcpayState(): array
    {
        return json_decode((string) file_get_contents(self::$fakeTcpayState), true);
    }

    protected function tamperFakeTcpayIntent(string $intentId, string $field, mixed $value): void
    {
        $context = stream_context_create(['http' => [
            'method'  => 'POST',
            'header'  => 'Content-Type: application/json',
            'content' => json_encode(['id' => $intentId, 'field' => $field, 'value' => $value]),
        ]]);
        file_get_contents('http://127.0.0.1:' . self::$fakeTcpayPort . '/__test/tamper', false, $context);
    }

    /**
     * Saves TC Pay settings to app_config and the in-memory OSPOS config.
     */
    protected function configureTcpay(array $overrides = [], string $sandboxKey = 'sandbox_tc_testkey'): void
    {
        config('Encryption')->key = str_repeat('a1', 32);
        Services::resetSingle('encrypter');

        $settings = array_merge([
            'tcpay_enabled'        => '1',
            'tcpay_environment'    => 'sandbox',
            'tcpay_api_url'        => $this->fakeTcpayUrl(),
            'tcpay_sandbox_key'    => $sandboxKey === '' ? '' : Services::encrypter()->encrypt($sandboxKey),
            'tcpay_live_key'       => '',
            'tcpay_test_buttons'   => '1',
            'tcpay_expiry_minutes' => '15',
            'currency_code'        => 'ZAR',
        ], $overrides);

        model(Appconfig::class)->batch_save($settings);
        Services::cache()->delete('settings');
        $config           = config(OSPOS::class);
        $config->settings = array_merge($config->settings, $settings);
    }
}
