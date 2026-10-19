<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use TCPay\PaymentIntentsClient;

class TcpayPaymentTender extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'intent_id'      => ['type' => 'CHAR', 'constraint' => 36],
            'reference'      => ['type' => 'VARCHAR', 'constraint' => 32],
            'client_ref'     => ['type' => 'VARCHAR', 'constraint' => 15],
            'environment'    => ['type' => 'VARCHAR', 'constraint' => 10],
            'amount_cents'   => ['type' => 'BIGINT'],
            'status'         => ['type' => 'VARCHAR', 'constraint' => 16],
            'failure_reason' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'payment_id'     => ['type' => 'BIGINT', 'null' => true],
            'session_token'  => ['type' => 'CHAR', 'constraint' => 32],
            'employee_id'    => ['type' => 'INT', 'constraint' => 10],
            'added_to_cart'  => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'created_at'     => ['type' => 'DATETIME'],
            'updated_at'     => ['type' => 'DATETIME'],
        ]);
        $this->forge->addPrimaryKey('intent_id');
        $this->forge->addUniqueKey('reference');
        $this->forge->addKey(['session_token', 'status']);
        $this->forge->addKey('employee_id');
        $this->forge->createTable('tcpay_intents', true, ['ENGINE' => 'InnoDB']);

        $this->db->table('app_config')->ignore(true)->insertBatch([
            ['key' => 'tcpay_enabled', 'value' => '0'],
            ['key' => 'tcpay_environment', 'value' => 'sandbox'],
            ['key' => 'tcpay_api_url', 'value' => PaymentIntentsClient::DEFAULT_BASE_URL],
            ['key' => 'tcpay_sandbox_key', 'value' => ''],
            ['key' => 'tcpay_live_key', 'value' => ''],
            ['key' => 'tcpay_test_buttons', 'value' => '1'],
            ['key' => 'tcpay_expiry_minutes', 'value' => '15'],
        ]);
    }

    public function down(): void
    {
        $this->db->table('app_config')->like('key', 'tcpay_', 'after')->delete();

        // The intents table links sales to TC Pay payments and is needed for
        // reconciliation, so it is kept on rollback.
    }
}
