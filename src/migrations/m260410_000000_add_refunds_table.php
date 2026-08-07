<?php

namespace NinetyNineX\SwishSuite\migrations;

use craft\db\Migration;

class m260410_000000_add_refunds_table extends Migration
{
    private const REFUNDS_TABLE = '{{%swish_suite_refunds}}';
    private const PAYMENTS_TABLE = '{{%swish_suite_payments}}';
    private const PAYMENT_FK_NAME = 'fk_swish_suite_refunds_paymentRecordId';
    private const DEFAULT_CURRENCY = 'SEK';
    private const DEFAULT_STATUS = 'CREATED';

    public function safeUp(): bool
    {
        if ($this->db->schema->getTableSchema(self::REFUNDS_TABLE) !== null) {
            echo "Refunds table already exists. Skipping.\n";
            return true;
        }

        $this->createTable(self::REFUNDS_TABLE, [
            'id'                       => $this->primaryKey(),
            'refundId'                 => $this->string(32)->notNull(),           // UUID without hyphens, uppercase
            'paymentRecordId'          => $this->integer()->notNull(),            // FK to swish_suite_payments
            'originalPaymentReference' => $this->string(50)->notNull(),           // Swish reference for the original payment
            'paymentReference'         => $this->string(50),                      // Swish reference for the refund (after PAID)
            'payerPaymentReference'    => $this->string(35),                      // Internal refund reference
            'callbackUrl'              => $this->string(255)->notNull(),
            'callbackIdentifier'       => $this->string(36),                      // Callback validation UUID
            'payerAlias'               => $this->string(20),                      // Merchant Swish number (the refund sender)
            'payeeAlias'               => $this->string(20),                      // Consumer number (the refund recipient)
            'amount'                   => $this->integer()->notNull(),            // Amount in ore
            'currency'                 => $this->char(3)->notNull()->defaultValue(self::DEFAULT_CURRENCY),
            'message'                  => $this->string(50),
            'status'                   => $this->string(20)->notNull()->defaultValue(self::DEFAULT_STATUS),
            'swishResponse'            => $this->text(),                          // Received callback JSON
            'errorCode'                => $this->string(10),
            'errorMessage'             => $this->text(),
            'dateCreated'              => $this->dateTime()->notNull(),
            'dateUpdated'              => $this->dateTime()->notNull(),
            'uid'                      => $this->uid()->notNull(),
        ]);

        $this->createIndex(null, self::REFUNDS_TABLE, 'refundId', true);
        $this->createIndex(null, self::REFUNDS_TABLE, 'paymentRecordId');
        $this->createIndex(null, self::REFUNDS_TABLE, 'status');
        $this->addForeignKey(
            self::PAYMENT_FK_NAME,
            self::REFUNDS_TABLE,
            'paymentRecordId',
            self::PAYMENTS_TABLE,
            'id',
            'CASCADE',
            null
        );

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropForeignKeyByConstraintNameIfExists(self::PAYMENT_FK_NAME, self::REFUNDS_TABLE);
        $this->dropTableIfExists(self::REFUNDS_TABLE);
        return true;
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
