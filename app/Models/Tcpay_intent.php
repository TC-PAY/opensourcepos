<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * TC Pay payment intents created from the register. One row per intent;
 * a sale can have several (split tenders, a retry after a decline).
 */
class Tcpay_intent extends Model
{
    public const FINAL_STATES = ['SUCCESS', 'FAILED', 'EXPIRED'];

    protected $table            = 'tcpay_intents';
    protected $primaryKey       = 'intent_id';
    protected $useAutoIncrement = false;
    protected $useSoftDeletes   = false;
    protected $allowedFields    = [
        'intent_id',
        'reference',
        'client_ref',
        'environment',
        'amount_cents',
        'status',
        'failure_reason',
        'payment_id',
        'session_token',
        'employee_id',
        'added_to_cart',
        'created_at',
        'updated_at',
    ];

    public function getIntent(string $intentId): ?array
    {
        $row = $this->db->table($this->table)->where('intent_id', $intentId)->get()->getRowArray();

        return $row ?: null;
    }

    public function getByReference(string $reference): ?array
    {
        $row = $this->db->table($this->table)->where('reference', $reference)->get()->getRowArray();

        return $row ?: null;
    }

    public function addIntent(array $data): bool
    {
        $now = date('Y-m-d H:i:s');

        return $this->db->table($this->table)->ignore(true)->insert($data + [
            'added_to_cart' => 0,
            'created_at'    => $now,
            'updated_at'    => $now,
        ]);
    }

    public function lastError(): array
    {
        return $this->db->error();
    }

    /**
     * Moves an open intent to a final status. True only for the caller that
     * actually changed it, so concurrent polls apply the result once.
     */
    public function finalise(string $intentId, string $status, ?int $paymentId, ?string $failureReason): bool
    {
        $builder = $this->db->table($this->table);
        $builder->where('intent_id', $intentId)->whereNotIn('status', self::FINAL_STATES);
        $builder->update([
            'status'         => $status,
            'payment_id'     => $paymentId,
            'failure_reason' => $failureReason === null ? null : mb_substr($failureReason, 0, 255),
            'updated_at'     => date('Y-m-d H:i:s'),
        ]);

        return $this->db->affectedRows() === 1;
    }

    /**
     * Claims a confirmed intent for the cart. True only once per intent, so a
     * confirmed payment can never be added to a cart twice.
     */
    public function claimForCart(string $intentId, string $sessionToken): bool
    {
        $builder = $this->db->table($this->table);
        $builder->where('intent_id', $intentId)
            ->where('session_token', $sessionToken)
            ->where('status', 'SUCCESS')
            ->where('added_to_cart', 0);
        $builder->update(['added_to_cart' => 1, 'updated_at' => date('Y-m-d H:i:s')]);

        return $this->db->affectedRows() === 1;
    }

    /**
     * Confirmed payments this employee took that are not in any completed sale,
     * so a closed browser or lost session never hides money already received.
     */
    public function getUnattachedConfirmed(int $employeeId, string $paymentPrefix): array
    {
        $payments = $this->db->prefixTable('sales_payments');

        return $this->db->table($this->table . ' AS i')
            ->select('i.*')
            ->where('i.employee_id', $employeeId)
            ->where('i.status', 'SUCCESS')
            ->where("NOT EXISTS (SELECT 1 FROM {$payments} AS p WHERE p.payment_type = CONCAT(" . $this->db->escape($paymentPrefix) . ', i.reference))', null, false)
            ->orderBy('i.created_at', 'ASC')
            ->get()
            ->getResultArray();
    }
}
