<?php

namespace NinetyNineX\SwishSuite\records;

use Craft;
use craft\db\ActiveRecord;
use craft\elements\User;
use NinetyNineX\SwishSuite\enums\PaymentStatus;

/**
 * @property int|null $id
 * @property string $paymentId
 * @property string|null $paymentReference
 * @property string|null $payeePaymentReference
 * @property string|null $callbackIdentifier
 * @property int|null $userId
 * @property int $amount
 * @property string $currency
 * @property string $status
 * @property string|null $payerAlias
 * @property string|null $payeeAlias
 * @property string|null $message
 * @property string|null $callbackUrl
 * @property string|null $flow
 * @property string|null $paymentRequestToken
 * @property string|null $swishResponse
 * @property string|null $errorCode
 * @property string|null $errorMessage
 * @property string|null $commerceTransactionHash
 */
class Payment extends ActiveRecord
{
    public const STATUS_CREATED = PaymentStatus::Created->value;
    public const STATUS_PAID = PaymentStatus::Paid->value;
    public const STATUS_DECLINED = PaymentStatus::Declined->value;
    public const STATUS_CANCELLED = PaymentStatus::Cancelled->value;
    public const STATUS_ERROR = PaymentStatus::Error->value;

    public static function tableName(): string
    {
        return '{{%swish_suite_payments}}';
    }

    /**
     * Typed lookup by Swish payment ID.
     *
     * Yii's `find()->one()` is declared as returning `array|ActiveRecord|null`,
     * so callers cannot access typed properties on the result. Narrowing here
     * keeps every call site honest instead of repeating the check.
     */
    public static function findByPaymentId(string $paymentId): ?self
    {
        $record = static::find()->where(['paymentId' => $paymentId])->one();

        return $record instanceof self ? $record : null;
    }

    /**
     * Typed lookup matching either the Swish payment ID or the payment reference.
     *
     * Commerce refunds against the completed transaction, whose reference is the
     * Swish `paymentReference` rather than the original `paymentId`.
     */
    public static function findByPaymentIdOrReference(string $identifier): ?self
    {
        $record = static::find()
            ->where(['paymentId' => $identifier])
            ->orWhere(['paymentReference' => $identifier])
            ->one();

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
            [['paymentId', 'amount', 'currency', 'status', 'payeeAlias', 'callbackUrl'], 'required'],
            [['paymentId', 'paymentReference', 'payeePaymentReference', 'callbackIdentifier', 'currency', 'status', 'payerAlias', 'payeeAlias', 'message', 'callbackUrl', 'flow', 'paymentRequestToken', 'errorCode'], 'string'],
            [['swishResponse', 'errorMessage'], 'string'],
            [['commerceTransactionHash'], 'string', 'max' => 32],
            [['amount', 'userId'], 'integer'],
            [['paymentId'], 'unique'],
            [['status'], 'in', 'range' => PaymentStatus::values()],
            [['status'], 'default', 'value' => self::STATUS_CREATED],
        ];
    }

    public function statusEnum(): PaymentStatus
    {
        return PaymentStatus::from($this->status);
    }

    public function isInTerminalState(): bool
    {
        return PaymentStatus::tryFrom($this->status)?->isTerminal() ?? false;
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

    public function getUser(): ?User
    {
        if (!$this->userId) {
            return null;
        }

        return Craft::$app->getElements()->getElementById($this->userId, User::class);
    }
}
