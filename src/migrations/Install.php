<?php

namespace NinetyNineX\SwishSuite\migrations;

use craft\db\Migration;

/**
 * Creates the plugin's full schema.
 *
 * This is the single source of truth for the database layout — the plugin ships
 * no incremental migrations, so whatever this file creates *is* the schema.
 *
 * Each table is guarded independently and the whole migration is safe to re-run:
 * a partially-created schema (an interrupted install, or a manually dropped
 * table) is repaired rather than left half-built or aborted.
 */
class Install extends Migration
{
    private const PAYMENTS_TABLE = '{{%swish_suite_payments}}';
    private const REFUNDS_TABLE = '{{%swish_suite_refunds}}';
    private const USER_FK_NAME = 'fk_swish_suite_payments_userId';
    private const REFUNDS_PAYMENT_FK_NAME = 'fk_swish_suite_refunds_paymentRecordId';
    private const DEFAULT_CURRENCY = 'SEK';
    private const DEFAULT_STATUS = 'CREATED';
    private const DEFAULT_FLOW = 'ecommerce';

    public function safeUp(): bool
    {
        // Payments first — the refunds table carries a foreign key to it.
        $this->createPaymentsTable();
        $this->createRefundsTable();

        return true;
    }

    public function safeDown(): bool
    {
        // Reverse order: drop the child table before the parent it references.
        $this->dropForeignKeyByConstraintNameIfExists(self::REFUNDS_PAYMENT_FK_NAME, self::REFUNDS_TABLE);
        $this->dropTableIfExists(self::REFUNDS_TABLE);
        $this->dropForeignKeyByConstraintNameIfExists(self::USER_FK_NAME, self::PAYMENTS_TABLE);
        $this->dropTableIfExists(self::PAYMENTS_TABLE);

        return true;
    }

    private function createPaymentsTable(): void
    {
        if ($this->tableExists(self::PAYMENTS_TABLE)) {
            return;
        }

        $this->createTable(self::PAYMENTS_TABLE, [
            'id' => $this->primaryKey(),
            'paymentId' => $this->string(32)->notNull(),            // UUID without hyphens, uppercase
            'paymentReference' => $this->string(50),                       // Swish reference after PAID
            'payeePaymentReference' => $this->string(35),                       // Internal reference
            'callbackIdentifier' => $this->string(36),                       // Callback validation UUID
            'userId' => $this->integer(),                        // craft_users FK (nullable)
            'amount' => $this->integer()->notNull(),             // Amount in ore
            'currency' => $this->char(3)->notNull()->defaultValue(self::DEFAULT_CURRENCY),
            'status' => $this->string(20)->notNull()->defaultValue(self::DEFAULT_STATUS),
            'payerAlias' => $this->string(20),                       // Payer phone number
            'payeeAlias' => $this->string(20)->notNull(),            // Swish number merchant
            'message' => $this->string(50),
            'callbackUrl' => $this->string(255)->notNull(),
            'flow' => $this->string(20)->defaultValue(self::DEFAULT_FLOW), // ecommerce|mcommerce
            'paymentRequestToken' => $this->string(255),                      // M-Commerce app switch token
            'swishResponse' => $this->text(),                           // Received callback JSON
            'errorCode' => $this->string(10),
            'errorMessage' => $this->text(),
            'commerceTransactionHash' => $this->string(32)->null()->defaultValue(null),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid()->notNull(),
        ]);

        $this->createIndex(null, self::PAYMENTS_TABLE, 'paymentId', true);
        $this->createIndex(null, self::PAYMENTS_TABLE, 'status');
        $this->createIndex(null, self::PAYMENTS_TABLE, 'userId');
        $this->createIndex(null, self::PAYMENTS_TABLE, 'commerceTransactionHash');
        $this->addForeignKey(
            self::USER_FK_NAME,
            self::PAYMENTS_TABLE,
            'userId',
            '{{%users}}',
            'id',
            'SET NULL',
            null
        );
    }

    private function createRefundsTable(): void
    {
        if ($this->tableExists(self::REFUNDS_TABLE)) {
            return;
        }

        $this->createTable(self::REFUNDS_TABLE, [
            'id' => $this->primaryKey(),
            'refundId' => $this->string(32)->notNull(),           // UUID without hyphens, uppercase
            'paymentRecordId' => $this->integer()->notNull(),            // FK to swish_suite_payments
            'originalPaymentReference' => $this->string(50)->notNull(),           // Swish reference for the original payment
            'paymentReference' => $this->string(50),                      // Swish reference for the refund (after PAID)
            'payerPaymentReference' => $this->string(35),                      // Internal refund reference
            'callbackUrl' => $this->string(255)->notNull(),
            'callbackIdentifier' => $this->string(36),                      // Callback validation UUID
            'payerAlias' => $this->string(20),                      // Merchant Swish number (the refund sender)
            'payeeAlias' => $this->string(20),                      // Consumer number (the refund recipient)
            'amount' => $this->integer()->notNull(),            // Amount in ore
            'currency' => $this->char(3)->notNull()->defaultValue(self::DEFAULT_CURRENCY),
            'message' => $this->string(50),
            'status' => $this->string(20)->notNull()->defaultValue(self::DEFAULT_STATUS),
            'swishResponse' => $this->text(),                          // Received callback JSON
            'errorCode' => $this->string(10),
            'errorMessage' => $this->text(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid()->notNull(),
        ]);

        $this->createIndex(null, self::REFUNDS_TABLE, 'refundId', true);
        $this->createIndex(null, self::REFUNDS_TABLE, 'paymentRecordId');
        $this->createIndex(null, self::REFUNDS_TABLE, 'status');
        $this->addForeignKey(
            self::REFUNDS_PAYMENT_FK_NAME,
            self::REFUNDS_TABLE,
            'paymentRecordId',
            self::PAYMENTS_TABLE,
            'id',
            'CASCADE',
            null
        );
    }

    /**
     * Always re-reads the schema from the database rather than trusting Yii's
     * in-memory cache, so a table created earlier in this same process is seen.
     */
    private function tableExists(string $table): bool
    {
        return $this->db->schema->getTableSchema($table, true) !== null;
    }

    private function dropForeignKeyByConstraintNameIfExists(string $foreignKeyName, string $table): void
    {
        try {
            $this->dropForeignKey($foreignKeyName, $table);
        } catch (\Throwable $throwable) {
            if (stripos($throwable->getMessage(), 'exist') === false) {
                throw $throwable;
            }
        }
    }
}
