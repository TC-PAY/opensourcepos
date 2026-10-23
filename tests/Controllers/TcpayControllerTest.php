<?php

namespace Tests\Controllers;

use App\Libraries\Tcpay_lib;
use CodeIgniter\Config\Services;
use CodeIgniter\Database\Config;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Database;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\FakeTcpayServerTrait;
use Tests\Support\ItemFixtureTrait;

/**
 * @internal
 */
final class TcpayControllerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use ItemFixtureTrait;
    use EmployeeFixtureTrait;
    use FakeTcpayServerTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $seedOnce    = true;
    protected $refresh     = false;
    protected $namespace;
    private static bool $doneBootstrap = false;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::startFakeTcpay();
    }

    public static function tearDownAfterClass(): void
    {
        self::stopFakeTcpay();
        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        if (self::$doneBootstrap === false) {
            Config::seeder($this->DBGroup)->call('App\Database\Seeds\TestDatabaseBootstrapSeeder');
            Config::connect($this->DBGroup)->close();

            self::$doneBootstrap = true;
        }

        parent::setUp();
        $this->configureTcpay();
    }

    private function startSale(string $price = '100.00'): void
    {
        $cashierId = $this->createEmployee(grants: [
            ['permission_id' => 'sales', 'menu_group' => 'home'],
            ['permission_id' => 'sales_stock', 'menu_group' => 'home'],
        ]);
        $itemId = $this->createTestItem(HAS_NO_STOCK);

        $this->withSession([
            'person_id'  => $cashierId,
            'menu_group' => 'home',
            'sales_cart' => [
                1 => [
                    'item_id'               => $itemId,
                    'item_location'         => 1,
                    'stock_name'            => 'Test Location',
                    'line'                  => 1,
                    'name'                  => 'Test Item',
                    'item_number'           => 'TEST-1',
                    'attribute_values'      => null,
                    'attribute_dtvalues'    => null,
                    'description'           => 'Test Item',
                    'serialnumber'          => '',
                    'allow_alt_description' => false,
                    'is_serialized'         => false,
                    'quantity'              => '1',
                    'discount'              => '0',
                    'discount_type'         => 0,
                    'in_stock'              => '10',
                    'price'                 => $price,
                    'cost_price'            => '1.00',
                    'total'                 => $price,
                    'discounted_total'      => $price,
                    'print_option'          => PRINT_YES,
                    'stock_type'            => HAS_NO_STOCK,
                    'item_type'             => ITEM,
                    'hsn_code'              => null,
                    'tax_category_id'       => null,
                ],
            ],
        ]);
    }

    private function nextRequest(): void
    {
        $this->withSession($_SESSION);
    }

    private function createIntent(string $amount = '60.00', ?string $requestId = null): array
    {
        $response = $this->post('/tcpay/create', ['amount' => $amount, 'request_id' => $requestId ?? bin2hex(random_bytes(16))]);
        $this->nextRequest();

        return json_decode($response->getJSON(), true) + ['_status' => $response->response()->getStatusCode()];
    }

    private function cartTcpayPayments(): array
    {
        return array_filter(
            Services::session()->get('sales_payments') ?? [],
            static fn ($key) => str_starts_with((string) $key, Tcpay_lib::PAYMENT_PREFIX),
            ARRAY_FILTER_USE_KEY,
        );
    }

    public function testPostCreateRequiresEnabled(): void
    {
        $this->configureTcpay(['tcpay_enabled' => '0']);
        $this->startSale();

        $result = $this->createIntent();

        $this->assertSame(400, $result['_status']);
        $this->assertSame(lang('Tcpay.error_disabled'), $result['error']);
    }

    public function testPostCreateRejectsNonZarCurrency(): void
    {
        $this->configureTcpay(['currency_code' => 'USD']);
        $this->startSale();

        $result = $this->createIntent();

        $this->assertSame(400, $result['_status']);
        $this->assertSame(lang('Tcpay.error_currency'), $result['error']);
    }

    public function testPostCreateRejectsAmountAboveAmountDue(): void
    {
        $this->startSale('100.00');

        $result = $this->createIntent('100.01');

        $this->assertSame(400, $result['_status']);
        $this->assertSame(lang('Tcpay.error_amount_exceeds_due'), $result['error']);
    }

    public function testPostCreateRejectsMoreThanTwoDecimals(): void
    {
        $this->startSale();

        $result = $this->createIntent('10.005');

        $this->assertSame(400, $result['_status']);
    }

    public function testPostCreateRejectsMalformedRequestId(): void
    {
        $this->startSale();

        $result = $this->createIntent('10.00', 'not-a-request-id');

        $this->assertSame(400, $result['_status']);
        $this->assertSame(lang('Tcpay.error_request'), $result['error']);
    }

    public function testPostCreateReturnsPendingIntentWithoutTouchingCart(): void
    {
        $this->startSale();

        $result = $this->createIntent('60.00');

        $this->assertSame(200, $result['_status']);
        $this->assertSame('PENDING', $result['status']);
        $this->assertMatchesRegularExpression('/^PI-[0-9A-F]{12}$/', $result['reference']);
        $this->assertSame([], $this->cartTcpayPayments());

        $row = Database::connect()->table('tcpay_intents')->where('intent_id', $result['id'])->get()->getRowArray();
        $this->assertSame('6000', (string) $row['amount_cents']);
        $this->assertLessThanOrEqual(15, strlen($row['client_ref']));
    }

    public function testPostCreateReusesIntentForSameRequestId(): void
    {
        $this->startSale();
        $requestId = bin2hex(random_bytes(16));

        $first  = $this->createIntent('60.00', $requestId);
        $second = $this->createIntent('60.00', $requestId);

        $this->assertSame($first['id'], $second['id']);
    }

    public function testGetStatusAddsConfirmedPaymentToCartOnce(): void
    {
        $this->startSale();
        $intent = $this->createIntent('60.00');
        $this->tamperFakeTcpayIntent($intent['id'], 'status', 'SUCCESS');

        $this->get('/tcpay/status/' . $intent['id']);
        $this->nextRequest();
        $response = $this->get('/tcpay/status/' . $intent['id']);
        $this->nextRequest();

        $result = json_decode($response->getJSON(), true);
        $this->assertSame('SUCCESS', $result['status']);
        $this->assertTrue($result['in_cart']);

        $payments = $this->cartTcpayPayments();
        $this->assertCount(1, $payments);
        $payment = $payments[Tcpay_lib::PAYMENT_PREFIX . $intent['reference']];
        $this->assertSame(0, bccomp((string) $payment['payment_amount'], '60.00', 2));
        $this->assertSame($intent['reference'], $payment['reference_code']);
    }

    public function testGetStatusRejectsIntentFromAnotherSession(): void
    {
        $this->startSale();
        $intent = $this->createIntent();

        $this->startSale();
        $response = $this->get('/tcpay/status/' . $intent['id']);

        $response->assertStatus(404);
    }

    public function testGetStatusRejectsSuccessForDifferentAmount(): void
    {
        $this->startSale();
        $intent = $this->createIntent('60.00');
        $this->tamperFakeTcpayIntent($intent['id'], 'amount_cents', 1);
        $this->tamperFakeTcpayIntent($intent['id'], 'status', 'SUCCESS');

        $response = $this->get('/tcpay/status/' . $intent['id']);
        $this->nextRequest();

        $this->assertSame('PENDING', json_decode($response->getJSON(), true)['status']);
        $this->assertSame([], $this->cartTcpayPayments());
    }

    public function testGetStatusRejectsSuccessForDifferentReference(): void
    {
        $this->startSale();
        $intent = $this->createIntent('60.00');
        $this->tamperFakeTcpayIntent($intent['id'], 'client_ref', 'OS-SOMEONEELSE');
        $this->tamperFakeTcpayIntent($intent['id'], 'status', 'SUCCESS');

        $this->get('/tcpay/status/' . $intent['id']);
        $this->nextRequest();

        $this->assertSame([], $this->cartTcpayPayments());
    }

    public function testPostSimulateAddsPaymentOnSuccess(): void
    {
        $this->startSale();
        $intent = $this->createIntent('25.50');

        $response = $this->post('/tcpay/simulate/' . $intent['id'], ['outcome' => 'success']);
        $this->nextRequest();

        $this->assertSame('SUCCESS', json_decode($response->getJSON(), true)['status']);
        $this->assertCount(1, $this->cartTcpayPayments());
    }

    public function testPostSimulateFailureLeavesCartUnchanged(): void
    {
        $this->startSale();
        $intent = $this->createIntent();

        $response = $this->post('/tcpay/simulate/' . $intent['id'], ['outcome' => 'failure']);
        $this->nextRequest();

        $this->assertSame('FAILED', json_decode($response->getJSON(), true)['status']);
        $this->assertSame([], $this->cartTcpayPayments());
    }

    public function testPostSimulateRequiresTestButtons(): void
    {
        $this->configureTcpay(['tcpay_test_buttons' => '0']);
        $this->startSale();
        $intent = $this->createIntent();

        $response = $this->post('/tcpay/simulate/' . $intent['id'], ['outcome' => 'success']);

        $response->assertStatus(400);
        $this->assertSame([], $this->cartTcpayPayments());
    }

    public function testPostCancelMarksIntentFailed(): void
    {
        $this->startSale();
        $intent = $this->createIntent();

        $response = $this->post('/tcpay/cancel/' . $intent['id']);

        $result = json_decode($response->getJSON(), true);
        $this->assertSame('FAILED', $result['status']);
        $this->assertTrue($result['final']);
    }

    public function testGetUnattachedListsConfirmedPaymentsNotOnAnySale(): void
    {
        $this->startSale();
        $intent = $this->createIntent('60.00');
        $this->post('/tcpay/simulate/' . $intent['id'], ['outcome' => 'success']);
        $this->nextRequest();

        $response = $this->get('/tcpay/unattached');

        $references = array_column(json_decode($response->getJSON(), true), 'reference');
        $this->assertContains($intent['reference'], $references);
    }

    public function testPostSelectRemembersTcpayAsPaymentType(): void
    {
        $this->startSale();

        $this->post('/tcpay/select', ['selected' => '1']);
        $this->nextRequest();
        $this->assertSame(Tcpay_lib::PAYMENT_TYPE, Services::session()->get('payment_type'));

        $this->post('/tcpay/select', ['selected' => '0']);
        $this->nextRequest();
        $this->assertSame('', Services::session()->get('payment_type'));
    }

    public function testPostSelectRejectsWhenDisabled(): void
    {
        $this->configureTcpay(['tcpay_enabled' => '0']);
        $this->startSale();

        $this->post('/tcpay/select', ['selected' => '1']);
        $this->nextRequest();

        $this->assertSame('', Services::session()->get('payment_type'));
    }

    public function testGetIndexKeepsTcpaySelectedAfterReload(): void
    {
        $this->startSale();
        $this->post('/tcpay/select', ['selected' => '1']);
        $this->nextRequest();

        $response = $this->get('/sales');

        $this->assertStringContainsString('<option value="' . Tcpay_lib::PAYMENT_TYPE . '" selected>', $response->getBody());
    }

    public function testPostSimulateClearsTcpaySelectionOncePaid(): void
    {
        $this->startSale();
        $this->post('/tcpay/select', ['selected' => '1']);
        $this->nextRequest();
        $intent = $this->createIntent('25.50');

        $this->post('/tcpay/simulate/' . $intent['id'], ['outcome' => 'success']);
        $this->nextRequest();

        $this->assertSame('', Services::session()->get('payment_type'));
    }

    public function testPostCreateRequiresSalesPermission(): void
    {
        $noSalesId = $this->createEmployee(grants: [['permission_id' => 'customers', 'menu_group' => 'home']]);
        $this->withSession(['person_id' => $noSalesId, 'menu_group' => 'home']);

        $response = $this->post('/tcpay/create', ['amount' => '10.00', 'request_id' => bin2hex(random_bytes(16))]);

        $response->assertRedirect();
    }
}
