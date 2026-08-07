<?php

namespace NinetyNineX\SwishSuite\migrations;

use craft\db\Migration;

class Install extends Migration
{
    private const PAYMENTS_TABLE = '{{%swish_suite_payments}}';
    private const USER_FK_NAME = 'fk_swish_suite_payments_userId';
    private const DEFAULT_CURRENCY = 'SEK';
    private const DEFAULT_STATUS = 'CREATED';
    private const DEFAULT_FLOW = 'ecommerce';

    public function safeUp(): bool
    {
        if ($this->db->schema->getTableSchema(self::PAYMENTS_TABLE) !== null) {
            echo "Table already exists. Skipping.\n";
            return true;
        }

        $this->createTable(self::PAYMENTS_TABLE, [
            'id'                    => $this->primaryKey(),
            'paymentId'             => $this->string(32)->notNull(),            // UUID without hyphens, uppercase
            'paymentReference'      => $this->string(50),                       // Swish reference after PAID
            'payeePaymentReference' => $this->string(35),                       // Internal reference
            'callbackIdentifier'    => $this->string(36),                       // Callback validation UUID
            'userId'                => $this->integer(),                        // craft_users FK (nullable)
            'amount'                => $this->integer()->notNull(),             // Amount in ore
            'currency'              => $this->char(3)->notNull()->defaultValue(self::DEFAULT_CURRENCY),
            'status'                => $this->string(20)->notNull()->defaultValue(self::DEFAULT_STATUS),
            'payerAlias'            => $this->string(20),                       // Payer phone number
            'payeeAlias'            => $this->string(20)->notNull(),            // Swish number merchant
            'message'               => $this->string(50),
            'callbackUrl'           => $this->string(255)->notNull(),
            'flow'                  => $this->string(20)->defaultValue(self::DEFAULT_FLOW), // ecommerce|mcommerce
            'paymentRequestToken'   => $this->string(255),                      // M-Commerce app switch token
            'swishResponse'         => $this->text(),                           // Received callback JSON
            'errorCode'             => $this->string(10),
            'errorMessage'          => $this->text(),
            'dateCreated'           => $this->dateTime()->notNull(),
            'dateUpdated'           => $this->dateTime()->notNull(),
            'uid'                   => $this->uid()->notNull(),
        ]);

        $this->createIndex(null, self::PAYMENTS_TABLE, 'paymentId', true);
        $this->createIndex(null, self::PAYMENTS_TABLE, 'status');
        $this->createIndex(null, self::PAYMENTS_TABLE, 'userId');
        $this->addForeignKey(
            self::USER_FK_NAME,
            self::PAYMENTS_TABLE,
            'userId',
            '{{%users}}',
            'id',
            'SET NULL',
            null
        );

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropForeignKeyByConstraintNameIfExists(self::USER_FK_NAME, self::PAYMENTS_TABLE);
        $this->dropTableIfExists(self::PAYMENTS_TABLE);
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
