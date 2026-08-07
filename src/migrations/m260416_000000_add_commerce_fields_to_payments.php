<?php

namespace NinetyNineX\SwishSuite\migrations;

use craft\db\Migration;

class m260416_000000_add_commerce_fields_to_payments extends Migration
{
    private const PAYMENTS_TABLE = '{{%swish_suite_payments}}';

    public function safeUp(): bool
    {
        if (!$this->db->columnExists(self::PAYMENTS_TABLE, 'commerceTransactionHash')) {
            $this->addColumn(
                self::PAYMENTS_TABLE,
                'commerceTransactionHash',
                $this->string(32)->null()->defaultValue(null)->after('errorMessage')
            );

            $this->createIndex(
                null,
                self::PAYMENTS_TABLE,
                'commerceTransactionHash',
                false
            );
        }

        if (!$this->indexExists(self::PAYMENTS_TABLE, 'paymentId')) {
            $this->createIndex(
                null,
                self::PAYMENTS_TABLE,
                'paymentId',
                true
            );
        }

        return true;
    }

    private function indexExists(string $table, string $column): bool
    {
        $indexes = $this->db->schema->getTableIndexes($table);
        foreach ($indexes as $index) {
            if (in_array($column, $index->columns, true)) {
                return true;
            }
        }
        return false;
    }

    public function safeDown(): bool
    {
        $this->dropIndexes(self::PAYMENTS_TABLE, 'commerceTransactionHash');
        $this->dropColumn(self::PAYMENTS_TABLE, 'commerceTransactionHash');
        return true;
    }

    private function dropIndexes(string $table, string $column): void
    {
        $indexes = $this->db->schema->findIndexes($table);
        foreach ($indexes as $indexName => $index) {
            if (in_array($column, $index['columns'], true)) {
                $this->dropIndex($indexName, $table);
            }
        }
    }
}
