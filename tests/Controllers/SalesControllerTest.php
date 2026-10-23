<?php

namespace Tests\Controllers;

use CodeIgniter\Database\Config;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Config\Services;
use App\Models\Employee;
use Config\Database;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\SaleFixtureTrait;
use Tests\Support\FakeTcpayServerTrait;
use App\Libraries\Tcpay_lib;

/**
 * Regression tests for the Sales per-endpoint access-control bypass.
 *
 * A cashier holding only the base "sales" grant (no "reports_sales") must
 * not be able to reach the per-sale endpoints that getManage() gates
 * behind reports_sales: getSearch, getRow, getEdit, postSave, getReceipt,
 * getInvoice, getSendPdf, getSendReceipt.
 *
 * Also covers: getSearch() (the AJAX endpoint that
 * supplies every row of the Sales Takings list) was the one sibling that
 * returned the full ledger and was missing the reports_sales check.
 */
class SalesControllerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use ItemFixtureTrait;
    use EmployeeFixtureTrait;
    use SaleFixtureTrait;
    use FakeTcpayServerTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $seedOnce    = true;
    protected $refresh     = false;
    protected $namespace   = null;

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
    }

    protected function tearDown(): void
    {
        parent::tearDown();
    }

    /**
     * Cashier with bare "sales" + "sales_stock" grants. "sales_stock" is
     * required alongside "sales": Employee::has_module_grant('sales', ...)
     * treats the bare "sales" grant as insufficient once any sales_*
     * submodule permission exists in the permissions table (see
     * has_subpermissions()). Deliberately NOT "reports_sales" — that is the
     * grant this test exercises the gate on.
     */
    protected function createCashierEmployee(): int
    {
        return $this->createEmployee(
            first_name: 'Cashier',
            last_name:  'NoReports',
            email:      'cashier.' . uniqid() . '@test.com',
            username:   'cashier_' . uniqid(),
            grants: [
                ['permission_id' => 'sales', 'menu_group' => 'home'],
                ['permission_id' => 'sales_stock', 'menu_group' => 'home'],
            ],
        );
    }

    protected function createReportsSalesEmployee(): int
    {
        return $this->createEmployee(
            first_name: 'Supervisor',
            last_name:  'WithReports',
            email:      'supervisor.' . uniqid() . '@test.com',
            username:   'supervisor_' . uniqid(),
            grants: [
                ['permission_id' => 'sales', 'menu_group' => 'home'],
                ['permission_id' => 'sales_stock', 'menu_group' => 'home'],
                ['permission_id' => 'reports_sales', 'menu_group' => 'home'],
            ],
        );
    }

    protected function createCashierWithoutChangePriceGrant(): int
    {
        return $this->createEmployee(
            first_name: 'Cashier',
            last_name:  'NoChangePrice',
            email:      'cashier-nochangeprice.' . uniqid() . '@test.com',
            username:   'cashier_nochangeprice_' . uniqid(),
            grants: [
                ['permission_id' => 'sales', 'menu_group' => 'home'],
                ['permission_id' => 'sales_stock', 'menu_group' => 'home'],
            ],
        );
    }

    protected function createCashierWithChangePriceGrant(): int
    {
        return $this->createEmployee(
            first_name: 'Cashier',
            last_name:  'ChangePrice',
            email:      'cashier-changeprice.' . uniqid() . '@test.com',
            username:   'cashier_changeprice_' . uniqid(),
            grants: [
                ['permission_id' => 'sales', 'menu_group' => 'home'],
                ['permission_id' => 'sales_stock', 'menu_group' => 'home'],
                ['permission_id' => 'sales_change_price', 'menu_group' => 'home'],
            ],
        );
    }

    protected function loginAs(int $personId): void
    {
        $this->withSession([
            'person_id'  => $personId,
            'menu_group' => 'home',
        ]);
    }

    /**
     * Seeds a single cart line directly in the session, mirroring the shape
     * Sale_lib::add_item() produces, so tests can target postEditItem's
     * authorization branch without exercising the full add-item flow.
     */
    protected function seedCartLine(int $line, string $price, int $itemId): void
    {
        $this->withSession(array_merge($this->session, [
            'sales_cart' => [
                $line => [
                    'item_id'               => $itemId,
                    'item_location'         => 1,
                    'stock_name'            => 'Test Location',
                    'line'                  => $line,
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
        ]));
    }

    public function testCashierWithoutReportsSalesCannotGetRow(): void
    {
        $cashierId = $this->createCashierEmployee();
        $saleId = $this->createSale($cashierId);
        $this->loginAs($cashierId);

        $response = $this->get('/sales/row/' . $saleId);

        $response->assertStatus(403);
        $result = json_decode($response->getJSON(), true);
        $this->assertFalse($result['success']);
    }

    public function testCashierWithoutReportsSalesCannotGetSearch(): void
    {
        $cashierId = $this->createCashierEmployee();
        $this->createSale($cashierId);
        $this->loginAs($cashierId);

        $response = $this->get('/sales/search');

        $response->assertStatus(403);
        $result = json_decode($response->getJSON(), true);
        $this->assertFalse($result['success']);
        $this->assertSame(lang('Sales.not_authorized'), $result['message']);
    }

    public function testCashierWithoutReportsSalesCannotGetEdit(): void
    {
        $cashierId = $this->createCashierEmployee();
        $saleId = $this->createSale($cashierId);
        $this->loginAs($cashierId);

        $response = $this->get('/sales/edit/' . $saleId);

        $response->assertRedirect();
        $this->assertStringContainsString('no_access', $response->getRedirectUrl());
    }

    public function testCashierWithoutReportsSalesCannotGetReceipt(): void
    {
        $cashierId = $this->createCashierEmployee();
        $saleId = $this->createSale($cashierId);
        $this->loginAs($cashierId);

        $response = $this->get('/sales/receipt/' . $saleId);

        $response->assertRedirect();
        $this->assertStringContainsString('no_access', $response->getRedirectUrl());
    }

    public function testCashierWithoutReportsSalesCannotGetInvoice(): void
    {
        $cashierId = $this->createCashierEmployee();
        $saleId = $this->createSale($cashierId);
        $this->loginAs($cashierId);

        $response = $this->get('/sales/invoice/' . $saleId);

        $response->assertRedirect();
        $this->assertStringContainsString('no_access', $response->getRedirectUrl());
    }

    public function testCashierWithoutReportsSalesCannotSendPdf(): void
    {
        $cashierId = $this->createCashierEmployee();
        $saleId = $this->createSale($cashierId);
        $this->loginAs($cashierId);

        $response = $this->get('/sales/sendpdf/' . $saleId);

        $response->assertStatus(403);
        $result = json_decode($response->getJSON(), true);
        $this->assertFalse($result['success']);
    }

    public function testCashierWithoutReportsSalesCannotSendReceipt(): void
    {
        $cashierId = $this->createCashierEmployee();
        $saleId = $this->createSale($cashierId);
        $this->loginAs($cashierId);

        $response = $this->get('/sales/sendreceipt/' . $saleId);

        $response->assertStatus(403);
        $result = json_decode($response->getJSON(), true);
        $this->assertFalse($result['success']);
    }

    public function testCashierWithoutReportsSalesCannotPostSave(): void
    {
        $cashierId = $this->createCashierEmployee();
        $saleId = $this->createSale($cashierId);
        $this->loginAs($cashierId);

        $response = $this->post('/sales/save/' . $saleId, [
            'date'              => date('m/d/Y H:i:s'),
            'customer_id'       => '',
            'employee_id'       => $cashierId,
            'comment'           => 'tampered',
            'invoice_number'    => '',
            'number_of_payments'=> 0,
            'payment_type_new'  => '--',
            'payment_amount_new'=> ''
        ]);

        $response->assertStatus(403);
        $result = json_decode($response->getJSON(), true);
        $this->assertFalse($result['success']);
        $this->assertSame(lang('Sales.not_authorized'), $result['message']);
    }

    public function testEmployeeWithReportsSalesCanGetSearch(): void
    {
        $supervisorId = $this->createReportsSalesEmployee();
        $this->createSale($supervisorId);
        $this->loginAs($supervisorId);

        $response = $this->get('/sales/search');

        $response->assertStatus(200);
        $result = json_decode($response->getJSON(), true);
        $this->assertArrayNotHasKey('success', $result);
        $this->assertArrayHasKey('total', $result);
        $this->assertArrayHasKey('rows', $result);
        $this->assertArrayHasKey('payment_summary', $result);
    }

    public function testEmployeeWithReportsSalesCanGetRow(): void
    {
        $supervisorId = $this->createReportsSalesEmployee();
        $saleId = $this->createSale($supervisorId);
        $this->loginAs($supervisorId);

        $response = $this->get('/sales/row/' . $saleId);

        $response->assertStatus(200);
        $result = json_decode($response->getJSON(), true);
        $this->assertArrayNotHasKey('success', $result);
    }

    public function testEmployeeWithReportsSalesCanGetEdit(): void
    {
        $supervisorId = $this->createReportsSalesEmployee();
        $saleId = $this->createSale($supervisorId);
        $this->loginAs($supervisorId);

        $response = $this->get('/sales/edit/' . $saleId);

        $response->assertStatus(200);
    }

    public function testCashierWithoutGrantCannotChangePrice(): void
    {
        $cashierId = $this->createCashierWithoutChangePriceGrant();
        $this->loginAs($cashierId);
        $itemId = $this->createTestItem(HAS_NO_STOCK);
        $this->seedCartLine(1, '5.00', $itemId);

        $response = $this->post('/sales/editItem/1', [
            'description'  => 'Test Item',
            'serialnumber' => '',
            'price'        => '0.01',
            'quantity'     => '1',
            'discount'     => '0',
            'location'     => '1',
        ]);

        $response->assertStatus(200);
        $response->assertSee(lang('Sales.not_authorized'));

        $session = Services::session();
        $cart = $session->get('sales_cart');
        $this->assertEquals('5.00', $cart[1]['price']);
    }

    public function testCashierWithoutGrantCanEditQuantityAtSamePrice(): void
    {
        $cashierId = $this->createCashierWithoutChangePriceGrant();
        $this->loginAs($cashierId);
        $itemId = $this->createTestItem(HAS_NO_STOCK);
        $this->seedCartLine(1, '5.00', $itemId);

        $response = $this->post('/sales/editItem/1', [
            'description'  => 'Test Item',
            'serialnumber' => '',
            'price'        => '5.00',
            'quantity'     => '3',
            'discount'     => '0',
            'location'     => '1',
        ]);

        $response->assertStatus(200);

        $session = Services::session();
        $cart = $session->get('sales_cart');
        $this->assertEquals('3', $cart[1]['quantity']);
        $this->assertEquals('5.00', $cart[1]['price']);
    }

    public function testCashierWithGrantCanChangePrice(): void
    {
        $cashierId = $this->createCashierWithChangePriceGrant();
        $this->loginAs($cashierId);
        $itemId = $this->createTestItem(HAS_NO_STOCK);
        $this->seedCartLine(1, '5.00', $itemId);

        $response = $this->post('/sales/editItem/1', [
            'description'  => 'Test Item',
            'serialnumber' => '',
            'price'        => '0.01',
            'quantity'     => '1',
            'discount'     => '0',
            'location'     => '1',
        ]);

        $response->assertStatus(200);

        $session = Services::session();
        $cart = $session->get('sales_cart');
        $this->assertEquals('0.01', $cart[1]['price']);
    }

    public function testPostAddPaymentRejectsNegativeAmountTendered(): void
    {
        $cashierId = $this->createCashierWithoutChangePriceGrant();
        $this->loginAs($cashierId);
        $itemId = $this->createTestItem();
        $this->seedCartLine(1, '1.00', $itemId);

        $forgedPaymentType = lang('Sales.giftcard') . ':AUDIT-100';

        $response = $this->post('/sales/addPayment', [
            'payment_type'    => $forgedPaymentType,
            'amount_tendered' => '-500',
        ]);

        $response->assertStatus(200);
        $response->assertSee(lang('Sales.must_enter_numeric'));

        // ... and the negative payment must NOT have been added to the cart.
        $session  = Services::session();
        $payments = $session->get('sales_payments');
        $this->assertArrayNotHasKey($forgedPaymentType, (array) $payments);
    }

    public function testPostAddPaymentRejectsMalformedAmountTendered(): void
    {
        $cashierId = $this->createCashierWithoutChangePriceGrant();
        $this->loginAs($cashierId);
        $itemId = $this->createTestItem();
        $this->seedCartLine(1, '1.00', $itemId);

        $response = $this->post('/sales/addPayment', [
            'payment_type'    => lang('Sales.cash'),
            'amount_tendered' => 'ABC123',
        ]);

        $response->assertStatus(200);
        $response->assertSee(lang('Sales.must_enter_numeric'));

        $session  = Services::session();
        $payments = $session->get('sales_payments');
        $this->assertArrayNotHasKey(lang('Sales.cash'), (array) $payments);
    }

    public function testCashierWithoutReportsSalesCannotUnsuspend(): void
    {
        $victimId = $this->createReportsSalesEmployee();
        $saleId = $this->createSuspendedSale($victimId);

        $cashierId = $this->createCashierEmployee();
        $this->loginAs($cashierId);

        $response = $this->post('/sales/unsuspend', [
            'suspended_sale_id' => $saleId,
        ]);

        $response->assertStatus(403);
        $result = json_decode($response->getJSON(), true);
        $this->assertFalse($result['success']);

        $session = Services::session();
        $this->assertNotEquals($saleId, $session->get('sale_id'));
    }

    public function testEmployeeWithReportsSalesCannotUnsuspendCompletedSale(): void
    {
        $victimId = $this->createReportsSalesEmployee();
        $saleId = $this->createSale($victimId);

        $supervisorId = $this->createReportsSalesEmployee();
        $this->loginAs($supervisorId);

        $this->post('/sales/unsuspend', [
            'suspended_sale_id' => $saleId,
        ]);

        $session = Services::session();
        $this->assertNotEquals($saleId, $session->get('sale_id'));
    }

    public function testEmployeeWithReportsSalesCanUnsuspendSale(): void
    {
        $ownerId = $this->createReportsSalesEmployee();
        $saleId = $this->createSuspendedSale($ownerId);

        $supervisorId = $this->createReportsSalesEmployee();
        $this->loginAs($supervisorId);

        $this->post('/sales/unsuspend', [
            'suspended_sale_id' => $saleId,
        ]);

        $session = Services::session();
        $this->assertEquals($saleId, $session->get('sale_id'));
    }

    protected function createGiftcard(float $value): int
    {
        $giftcardNumber = random_int(1000000, 9999999);

        Database::connect()->table('giftcards')->insert([
            'giftcard_number' => $giftcardNumber,
            'value'           => $value,
            'deleted'         => 0,
            'person_id'       => null,
        ]);

        return $giftcardNumber;
    }

    protected function getGiftcardValue(int $giftcardNumber): float
    {
        $row = Database::connect()->table('giftcards')
            ->where('giftcard_number', $giftcardNumber)
            ->get()
            ->getRow();

        return (float) $row->value;
    }

    public function testCashierCannotForgeGiftcardPaymentTypeWithNegativeAmount(): void
    {
        $cashierId = $this->createCashierEmployee();
        $itemId = $this->createTestItem(HAS_NO_STOCK);
        $giftcardNumber = $this->createGiftcard(100.00);
        $this->loginAs($cashierId);
        $this->seedCartLine(1, '1.00', $itemId);

        $this->post('/sales/addPayment', [
            'payment_type'    => lang('Sales.giftcard') . ':' . $giftcardNumber,
            'amount_tendered' => '-500',
        ]);

        $payments = Services::session()->get('sales_payments');
        $this->assertEmpty($payments);
        $this->assertEqualsWithDelta(100.00, $this->getGiftcardValue($giftcardNumber), 0.001);
    }

    public function testCashierCanUseLegitimateGiftcardPayment(): void
    {
        $cashierId = $this->createCashierEmployee();
        $itemId = $this->createTestItem(HAS_NO_STOCK);
        $giftcardNumber = $this->createGiftcard(100.00);
        $this->loginAs($cashierId);
        $this->seedCartLine(1, '1.00', $itemId);

        $this->post('/sales/addPayment', [
            'payment_type'    => lang('Sales.giftcard'),
            'amount_tendered' => (string) $giftcardNumber,
        ]);

        $payments = Services::session()->get('sales_payments');
        $this->assertNotEmpty($payments);
        $this->assertArrayHasKey(lang('Sales.giftcard') . ':' . $giftcardNumber, $payments);
    }

    public function testCashierCannotSubmitNegativeAmountForReferenceCodePayment(): void
    {
        $cashierId = $this->createCashierEmployee();
        $itemId = $this->createTestItem(HAS_NO_STOCK);
        $this->loginAs($cashierId);
        $this->seedCartLine(1, '1.00', $itemId);

        $this->post('/sales/addPayment', [
            'payment_type'    => lang('Sales.debit'),
            'amount_tendered' => '-25',
            'reference_code'  => 'ABC123',
        ]);

        $payments = Services::session()->get('sales_payments');
        $this->assertEmpty($payments);
    }

    public function testCashierCanUseLegitimateReferenceCodePayment(): void
    {
        $cashierId = $this->createCashierEmployee();
        $itemId = $this->createTestItem(HAS_NO_STOCK);
        $this->loginAs($cashierId);
        $this->seedCartLine(1, '1.00', $itemId);

        $this->post('/sales/addPayment', [
            'payment_type'    => lang('Sales.debit'),
            'amount_tendered' => '25.00',
            'reference_code'  => 'ABC123',
        ]);

        $payments = Services::session()->get('sales_payments');
        $this->assertNotEmpty($payments);
        $this->assertArrayHasKey(lang('Sales.debit'), $payments);
        $this->assertSame('ABC123', $payments[lang('Sales.debit')]['reference_code']);
    }

    public function testCashierCannotSubmitNegativeAmountForCashPayment(): void
    {
        $cashierId = $this->createCashierEmployee();
        $itemId = $this->createTestItem(HAS_NO_STOCK);
        $this->loginAs($cashierId);
        $this->seedCartLine(1, '1.00', $itemId);

        $this->post('/sales/addPayment', [
            'payment_type'    => lang('Sales.cash'),
            'amount_tendered' => '-10',
        ]);

        $payments = Services::session()->get('sales_payments');
        $this->assertEmpty($payments);
    }

    public function testCashierCanUseLegitimateCashPayment(): void
    {
        $cashierId = $this->createCashierEmployee();
        $itemId = $this->createTestItem(HAS_NO_STOCK);
        $this->loginAs($cashierId);
        $this->seedCartLine(1, '1.00', $itemId);

        $this->post('/sales/addPayment', [
            'payment_type'    => lang('Sales.cash'),
            'amount_tendered' => '10.00',
        ]);

        $payments = Services::session()->get('sales_payments');
        $this->assertNotEmpty($payments);
        $this->assertArrayHasKey(lang('Sales.cash'), $payments);
    }

    public function testPostCompleteRejectsSaleWithInsufficientPayments(): void
    {
        $cashierId = $this->createCashierEmployee();
        $itemId = $this->createTestItem(HAS_NO_STOCK);
        $this->loginAs($cashierId);
        $this->seedCartLine(1, '100.00', $itemId);
        $this->withSession(array_merge($this->session, ['sale_id' => NEW_ENTRY]));

        $salesCountBefore = Database::connect()->table('sales')->countAllResults();

        $this->post('/sales/complete');

        $salesCountAfter = Database::connect()->table('sales')->countAllResults();
        $this->assertSame($salesCountBefore, $salesCountAfter);
    }

    public function testPostCompleteAllowsZeroPaymentQuoteCompletion(): void
    {
        $cashierId = $this->createCashierEmployee();
        $itemId = $this->createTestItem(HAS_NO_STOCK);
        $this->loginAs($cashierId);
        $this->seedCartLine(1, '100.00', $itemId);
        $this->withSession(array_merge($this->session, ['sale_id' => NEW_ENTRY, 'sales_mode' => 'sale_quote']));

        $salesCountBefore = Database::connect()->table('sales')->countAllResults();

        $response = $this->post('/sales/complete');

        $salesCountAfter = Database::connect()->table('sales')->countAllResults();
        $this->assertSame($salesCountBefore + 1, $salesCountAfter);
        $response->assertDontSee(lang('Sales.amount_due_not_covered'));
    }

    public function testPostCompleteAllowsZeroPaymentInvoiceCompletion(): void
    {
        $cashierId = $this->createCashierEmployee();
        $itemId = $this->createTestItem(HAS_NO_STOCK);
        $this->loginAs($cashierId);
        $this->seedCartLine(1, '100.00', $itemId);
        $this->withSession(array_merge($this->session, ['sale_id' => NEW_ENTRY, 'sales_mode' => 'sale_invoice']));

        $salesCountBefore = Database::connect()->table('sales')->countAllResults();

        $response = $this->post('/sales/complete');

        $salesCountAfter = Database::connect()->table('sales')->countAllResults();
        $this->assertSame($salesCountBefore + 1, $salesCountAfter);
        $response->assertDontSee(lang('Sales.amount_due_not_covered'));
    }

    public function testRegisterEscapesMaliciousTaxName(): void
    {
        $cashierId = $this->createCashierEmployee();
        $itemId = $this->createTestItem();

        Database::connect()->table('items_taxes')->insert([
            'item_id' => $itemId,
            'name'    => '<svg onload=alert(1)>',
            'percent' => 5,
        ]);

        $this->loginAs($cashierId);
        $this->seedCartLine(1, '5.00', $itemId);

        $response = $this->get('/sales');

        $response->assertStatus(200);
        $body = $response->getBody();
        $this->assertStringNotContainsString('<svg onload', $body);
        $this->assertStringContainsString('&lt;svg', $body);
    }

    public function testPostUnsuspendRejectsNonSuspendedSaleWithoutClearingActiveCart(): void
    {
        $employeeId = $this->createReportsSalesEmployee();
        $itemId = $this->createTestItem(HAS_NO_STOCK);
        $notSuspendedSaleId = $this->createSale($employeeId);
        $this->loginAs($employeeId);
        $this->seedCartLine(1, '1.00', $itemId);
        $this->withSession(array_merge($this->session, ['sale_id' => NEW_ENTRY]));

        $this->post('/sales/unsuspend', [
            'suspended_sale_id' => (string) $notSuspendedSaleId,
        ]);

        $cart = Services::session()->get('sales_cart');
        $this->assertNotEmpty($cart);
        $this->assertArrayHasKey(1, $cart);
        $this->assertSame($itemId, $cart[1]['item_id']);
        $this->assertSame(NEW_ENTRY, Services::session()->get('sale_id'));
    }

    public function testPostUnsuspendReplacesCartForValidSuspendedSale(): void
    {
        $employeeId = $this->createReportsSalesEmployee();
        $itemId = $this->createTestItem(HAS_NO_STOCK);
        $suspendedSaleId = $this->createSuspendedSale($employeeId);
        $this->loginAs($employeeId);
        $this->seedCartLine(1, '1.00', $itemId);

        $this->post('/sales/unsuspend', [
            'suspended_sale_id' => (string) $suspendedSaleId,
        ]);

        $this->assertSame($suspendedSaleId, Services::session()->get('sale_id'));
        $cart = Services::session()->get('sales_cart');
        $this->assertNotEmpty($cart);
        $this->assertNotSame($itemId, array_values($cart)[0]['item_id']);
    }

    private function seedTcpayPayment(string $reference, string $amount): void
    {
        $this->withSession(array_merge($this->session, [
            'sale_id'        => NEW_ENTRY,
            'sales_payments' => [
                Tcpay_lib::PAYMENT_PREFIX . $reference => [
                    'payment_type'    => Tcpay_lib::PAYMENT_PREFIX . $reference,
                    'payment_amount'  => $amount,
                    'cash_refund'     => 0,
                    'cash_adjustment' => CASH_ADJUSTMENT_FALSE,
                    'reference_code'  => $reference,
                ],
            ],
        ]));
    }

    /**
     * Takes a real (fake-API) TC Pay payment for the whole cart through the
     * payment window endpoints, leaving the confirmed line in the cart.
     */
    private function payCartWithTcpay(string $amount): array
    {
        $this->withSession(array_merge($this->session, ['sale_id' => NEW_ENTRY]));
        $response = $this->post('/tcpay/create', ['amount' => $amount, 'request_id' => bin2hex(random_bytes(16))]);
        $intent = json_decode($response->getJSON(), true);
        $this->withSession($_SESSION);
        $this->post('/tcpay/simulate/' . $intent['id'], ['outcome' => 'success']);
        $this->withSession($_SESSION);

        return $intent;
    }

    private function tcpayPaymentRows(string $reference): array
    {
        return Database::connect()->table('sales_payments')
            ->where('payment_type', Tcpay_lib::PAYMENT_PREFIX . $reference)
            ->get()->getResultArray();
    }

    public function testPostAddPayment_RejectsTcpayPaymentType(): void
    {
        $this->configureTcpay();
        $cashierId = $this->createCashierEmployee();
        $this->loginAs($cashierId);
        $this->seedCartLine(1, '100.00', $this->createTestItem(HAS_NO_STOCK));

        $response = $this->post('/sales/addPayment', ['payment_type' => Tcpay_lib::PAYMENT_TYPE, 'amount_tendered' => '100.00']);

        $response->assertSee(lang('Tcpay.error_use_payment_window'));
        $this->assertEmpty(Services::session()->get('sales_payments'));
    }

    public function testPostAddPayment_RejectsForgedTcpayReference(): void
    {
        $this->configureTcpay();
        $cashierId = $this->createCashierEmployee();
        $this->loginAs($cashierId);
        $this->seedCartLine(1, '100.00', $this->createTestItem(HAS_NO_STOCK));

        $response = $this->post('/sales/addPayment', ['payment_type' => Tcpay_lib::PAYMENT_PREFIX . 'PI-FORGED000000', 'amount_tendered' => '100.00']);

        $response->assertSee(lang('Tcpay.error_use_payment_window'));
        $this->assertEmpty(Services::session()->get('sales_payments'));
    }

    public function testGetDeletePayment_RejectsTcpayPayment(): void
    {
        helper('url');
        $cashierId = $this->createCashierEmployee();
        $this->loginAs($cashierId);
        $this->seedTcpayPayment('PI-AAAAAAAAAAAA', '10.00');

        $response = $this->get('/sales/deletePayment/' . base64url_encode(Tcpay_lib::PAYMENT_PREFIX . 'PI-AAAAAAAAAAAA'));

        $response->assertSee(lang('Tcpay.error_cannot_remove'));
        $this->assertArrayHasKey(Tcpay_lib::PAYMENT_PREFIX . 'PI-AAAAAAAAAAAA', Services::session()->get('sales_payments'));
    }

    public function testPostCancel_RejectsCartWithTcpayPayment(): void
    {
        $cashierId = $this->createCashierEmployee();
        $this->loginAs($cashierId);
        $this->seedCartLine(1, '10.00', $this->createTestItem(HAS_NO_STOCK));
        $this->seedTcpayPayment('PI-BBBBBBBBBBBB', '10.00');

        $response = $this->post('/sales/cancel');

        $response->assertSee(lang('Tcpay.error_cannot_cancel'));
        $this->assertNotEmpty(Services::session()->get('sales_cart'));
    }

    public function testPostSuspend_RejectsCartWithTcpayPayment(): void
    {
        $cashierId = $this->createCashierEmployee();
        $this->loginAs($cashierId);
        $this->seedCartLine(1, '10.00', $this->createTestItem(HAS_NO_STOCK));
        $this->seedTcpayPayment('PI-CCCCCCCCCCCC', '10.00');
        $suspendedBefore = Database::connect()->table('sales')->where('sale_status', SUSPENDED)->countAllResults();

        $response = $this->post('/sales/suspend');

        $response->assertSee(lang('Tcpay.error_cannot_suspend'));
        $this->assertSame($suspendedBefore, Database::connect()->table('sales')->where('sale_status', SUSPENDED)->countAllResults());
    }

    public function testPostComplete_RejectsUnverifiedTcpayPayment(): void
    {
        $cashierId = $this->createCashierEmployee();
        $this->loginAs($cashierId);
        $this->seedCartLine(1, '100.00', $this->createTestItem(HAS_NO_STOCK));
        $this->seedTcpayPayment('PI-DDDDDDDDDDDD', '100.00');
        $salesBefore = Database::connect()->table('sales')->countAllResults();

        $response = $this->post('/sales/complete');

        $response->assertSee(lang('Tcpay.error_unverified_payment', ['PI-DDDDDDDDDDDD']));
        $this->assertSame($salesBefore, Database::connect()->table('sales')->countAllResults());
    }

    public function testPostComplete_AcceptsConfirmedTcpayPayment(): void
    {
        $this->configureTcpay();
        $cashierId = $this->createCashierEmployee();
        $this->loginAs($cashierId);
        $this->seedCartLine(1, '100.00', $this->createTestItem(HAS_NO_STOCK));
        $intent = $this->payCartWithTcpay('100.00');

        $response = $this->post('/sales/complete');

        $response->assertSee('TC Pay ' . $intent['reference']);
        $rows = $this->tcpayPaymentRows($intent['reference']);
        $this->assertCount(1, $rows);
        $this->assertSame(0, bccomp((string) $rows[0]['payment_amount'], '100.00', 2));
        $this->assertSame($intent['reference'], $rows[0]['reference_code']);
    }

    public function testPostComplete_RejectsTcpayPaymentAlreadyOnAnotherSale(): void
    {
        $this->configureTcpay();
        $cashierId = $this->createCashierEmployee();
        $this->loginAs($cashierId);
        $itemId = $this->createTestItem(HAS_NO_STOCK);
        $this->seedCartLine(1, '100.00', $itemId);
        $intent = $this->payCartWithTcpay('100.00');
        $this->post('/sales/complete');
        $this->withSession($_SESSION);

        $this->seedCartLine(1, '100.00', $itemId);
        $this->seedTcpayPayment($intent['reference'], '100.00');
        $response = $this->post('/sales/complete');

        $response->assertSee(lang('Tcpay.error_payment_already_used', [$intent['reference']]));
        $this->assertCount(1, $this->tcpayPaymentRows($intent['reference']));
    }

    public function testPostComplete_RejectsTcpayPaymentWithChangedAmount(): void
    {
        $this->configureTcpay();
        $cashierId = $this->createCashierEmployee();
        $this->loginAs($cashierId);
        $this->seedCartLine(1, '100.00', $this->createTestItem(HAS_NO_STOCK));
        $intent = $this->payCartWithTcpay('40.00');
        $this->seedTcpayPayment($intent['reference'], '100.00');

        $response = $this->post('/sales/complete');

        $response->assertSee(lang('Tcpay.error_unverified_payment', [$intent['reference']]));
        $this->assertCount(0, $this->tcpayPaymentRows($intent['reference']));
    }

    private function createSaleWithTcpayPayment(int $employeeId): array
    {
        $reference = 'PI-' . strtoupper(bin2hex(random_bytes(6)));
        $saleId = $this->createSale($employeeId);
        $db = Database::connect();
        $db->table('sales_payments')->insert([
            'sale_id'         => $saleId,
            'payment_type'    => Tcpay_lib::PAYMENT_PREFIX . $reference,
            'payment_amount'  => 1.00,
            'cash_refund'     => 0,
            'cash_adjustment' => 0,
            'employee_id'     => $employeeId,
            'reference_code'  => $reference,
        ]);

        return ['sale_id' => $saleId, 'payment_id' => (int) $db->insertID(), 'reference' => $reference];
    }

    private function postSaleEdit(int $saleId, int $employeeId, array $payment, array $extra = []): array
    {
        $response = $this->post('/sales/save/' . $saleId, array_merge([
            'date'               => date('m/d/Y H:i:s'),
            'customer_id'        => '',
            'employee_id'        => $employeeId,
            'comment'            => 'edited',
            'invoice_number'     => '',
            'number_of_payments' => 1,
            'payment_id_0'       => $payment['payment_id'],
            'payment_type_0'     => $payment['type'],
            'payment_amount_0'   => $payment['amount'],
            'refund_type_0'      => lang('Sales.cash'),
            'refund_amount_0'    => '0.00',
            'reference_code_0'   => $payment['reference'],
            'payment_type_new'   => PAYMENT_TYPE_UNASSIGNED,
            'payment_amount_new' => '',
        ], $extra));

        return json_decode($response->getJSON(), true);
    }

    public function testPostSave_RejectsChangingTcpayPaymentAmount(): void
    {
        $supervisorId = $this->createReportsSalesEmployee();
        $sale = $this->createSaleWithTcpayPayment($supervisorId);
        $this->loginAs($supervisorId);

        $result = $this->postSaleEdit($sale['sale_id'], $supervisorId, [
            'payment_id' => $sale['payment_id'], 'reference' => $sale['reference'], 'type' => Tcpay_lib::PAYMENT_PREFIX . $sale['reference'], 'amount' => '0.00',
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame(lang('Tcpay.error_edit_locked'), $result['message']);
        $this->assertCount(1, $this->tcpayPaymentRows($sale['reference']));
    }

    public function testPostSave_RejectsChangingTcpayPaymentType(): void
    {
        $supervisorId = $this->createReportsSalesEmployee();
        $sale = $this->createSaleWithTcpayPayment($supervisorId);
        $this->loginAs($supervisorId);

        $result = $this->postSaleEdit($sale['sale_id'], $supervisorId, [
            'payment_id' => $sale['payment_id'], 'reference' => $sale['reference'], 'type' => lang('Sales.cash'), 'amount' => '1.00',
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame(lang('Tcpay.error_edit_locked'), $result['message']);
    }

    public function testPostSave_RejectsTypingNewTcpayPayment(): void
    {
        $supervisorId = $this->createReportsSalesEmployee();
        $sale = $this->createSaleWithTcpayPayment($supervisorId);
        $this->loginAs($supervisorId);

        $result = $this->postSaleEdit($sale['sale_id'], $supervisorId, [
            'payment_id' => $sale['payment_id'], 'reference' => $sale['reference'], 'type' => Tcpay_lib::PAYMENT_PREFIX . $sale['reference'], 'amount' => '1.00',
        ], ['payment_type_new' => Tcpay_lib::PAYMENT_TYPE, 'payment_amount_new' => '5.00']);

        $this->assertFalse($result['success']);
        $this->assertSame(lang('Tcpay.error_use_payment_window'), $result['message']);
    }

    public function testPostSave_AcceptsUnchangedTcpayPayment(): void
    {
        $supervisorId = $this->createReportsSalesEmployee();
        $sale = $this->createSaleWithTcpayPayment($supervisorId);
        $this->loginAs($supervisorId);

        $result = $this->postSaleEdit($sale['sale_id'], $supervisorId, [
            'payment_id' => $sale['payment_id'], 'reference' => $sale['reference'], 'type' => Tcpay_lib::PAYMENT_PREFIX . $sale['reference'], 'amount' => '1.00',
        ]);

        $this->assertTrue($result['success'], $result['message'] ?? '');
        $this->assertCount(1, $this->tcpayPaymentRows($sale['reference']));
    }

    public function testGetEdit_ShowsTcpayPaymentReadOnly(): void
    {
        $supervisorId = $this->createReportsSalesEmployee();
        $sale = $this->createSaleWithTcpayPayment($supervisorId);
        $this->loginAs($supervisorId);

        $response = $this->get('/sales/edit/' . $sale['sale_id']);

        $response->assertSee('id="payment_type_0"');
        $response->assertDontSee('id="payment_types_0"');
    }

    public function testGetIndex_OffersTcpayWhenEnabled(): void
    {
        $this->configureTcpay();
        $cashierId = $this->createCashierEmployee();
        $this->loginAs($cashierId);
        $this->seedCartLine(1, '10.00', $this->createTestItem(HAS_NO_STOCK));

        $response = $this->get('/sales');

        $response->assertSee('id="tcpay_modal"');
        $response->assertSee('value="' . Tcpay_lib::PAYMENT_TYPE . '"');
    }

    public function testGetIndex_HidesTcpayWhenDisabled(): void
    {
        $this->configureTcpay(['tcpay_enabled' => '0']);
        $cashierId = $this->createCashierEmployee();
        $this->loginAs($cashierId);

        $response = $this->get('/sales');

        $response->assertDontSee('id="tcpay_modal"');
    }
}
