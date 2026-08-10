<?php

namespace NinetyNineX\SwishSuite\records;

use craft\db\ActiveRecord;
use NinetyNineX\SwishSuite\enums\RefundStatus;

/**
 * @property int|null $id
 * @property string $refundId
 * @property int $paymentRecordId
 * @property string $originalPaymentReference
 * @property string|null $paymentReference
 * @property string|null $payerPaymentReference
 * @property string $callbackUrl
 * @property string|null $callbackIdentifier
 * @property string|null $payerAlias
 * @property string|null $payeeAlias
 * @property int $amount
 * @property string $currency
 * @property string|null $message
 * @property string $status
 * @property string|null $swishResponse
 * @property string|null $errorCode
 * @property string|null $errorMessage
 */
class Refund extends ActiveRecord
{
    public const STATUS_CREATED = RefundStatus::Created->value;
    public const STATUS_PAID = RefundStatus::Paid->value;
    public const STATUS_DECLINED = RefundStatus::Declined->value;
    public const STATUS_ERROR = RefundStatus::Error->value;

    public static function tableName(): string
    {
        return '{{%swish_suite_refunds}}';
    }

    /**
     * Typed lookup by Swish refund ID.
     *
     * Yii's `find()->one()` is declared as returning `array|ActiveRecord|null`,
     * so callers cannot access typed properties on the result. Narrowing here
     * keeps every call site honest instead of repeating the check.
     */
    public static function findByRefundId(string $refundId): ?self
    {
        $record = static::find()->where(['refundId' => $refundId])->one();

        return $record instanceof self ? $record : null;
    }

    /** Typed lookup by primary key. */
    public static function findById(int $id): ?self
    {
        $record = static::find()->where(['id' => $id])->one();

        return $record instanceof self ? $record : null;
    }

    /** @return array<int, array<int|string, mixed>> */
    public function rules(): array
    {
        return [
            [['refundId', 'paymentRecordId', 'originalPaymentReference', 'amount', 'currency', 'status', 'callbackUrl'], 'required'],
            [['refundId', 'originalPaymentReference', 'paymentReference', 'payerPaymentReference', 'callbackUrl', 'callbackIdentifier', 'payerAlias', 'payeeAlias', 'message', 'currency', 'status', 'errorCode'], 'string'],
            [['swishResponse', 'errorMessage'], 'string'],
            [['amount', 'paymentRecordId'], 'integer'],
            [['refundId'], 'unique'],
            [['status'], 'in', 'range' => RefundStatus::values()],
            [['status'], 'default', 'value' => self::STATUS_CREATED],
        ];
    }

    public function statusEnum(): RefundStatus
    {
        return RefundStatus::from($this->status);
    }

    public function isInTerminalState(): bool
    {
        return RefundStatus::tryFrom($this->status)?->isTerminal() ?? false;
    }

    /** @return array<string, mixed>|null */
    public function getSwishResponseArray(): ?array
    {
        if (empty($this->swishResponse)) {
            return null;
        }

        $decoded = json_decode($this->swishResponse, true);

        return is_array($decoded) ? $decoded : null;
    }

    /** @param array<string, mixed>|null $data */
    public function setSwishResponseArray(?array $data): void
    {
        $this->swishResponse = $data !== null
            ? (json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: null)
            : null;
    }

    public function getPayment(): ?Payment
    {
        return Payment::findById($this->paymentRecordId);
    }
}
