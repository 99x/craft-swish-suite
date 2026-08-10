<?php

namespace NinetyNineX\SwishSuite\services;

use craft\commerce\Plugin as Commerce;
use craft\commerce\records\Transaction as TransactionRecord;
use NinetyNineX\SwishSuite\enums\PaymentStatus;
use NinetyNineX\SwishSuite\enums\RefundStatus;
use NinetyNineX\SwishSuite\events\PaymentEvent;
use NinetyNineX\SwishSuite\events\RefundEvent;
use NinetyNineX\SwishSuite\records\Payment;
use NinetyNineX\SwishSuite\records\Refund;
use NinetyNineX\SwishSuite\SwishSuite;
use yii\base\Component;
use yii\base\Event;

class CallbackHandlerService extends Component
{
    public const EVENT_AFTER_PAYMENT_CALLBACK = 'afterPaymentCallback';
    public const EVENT_AFTER_REFUND_CALLBACK = 'afterRefundCallback';

    /**
     * Validates callback identifier against persisted value
     */
    public function validateCallback(?string $receivedIdentifier, string $entityId): bool
    {
        if (!$receivedIdentifier) {
            return false;
        }

        $payment = Payment::findByPaymentId($entityId);
        if ($payment && is_string($payment->callbackIdentifier)) {
            return hash_equals($payment->callbackIdentifier, $receivedIdentifier);
        }

        $refund = Refund::findByRefundId($entityId);
        if ($refund && is_string($refund->callbackIdentifier)) {
            return hash_equals($refund->callbackIdentifier, $receivedIdentifier);
        }

        return false;
    }

    /** @param array<string, mixed> $payload */
    public function processPaymentCallback(array $payload): void
    {
        $paymentId = is_string($payload['id'] ?? null) ? $payload['id'] : null;
        if ($paymentId === null) {
            SwishSuite::getInstance()->helpers->logWarning('Callback payload missing id', __METHOD__);
            return;
        }

        try {
            $payment = Payment::findByPaymentId($paymentId);
            if (!$payment) {
                SwishSuite::getInstance()->helpers->logWarning("Payment not found: {$paymentId}", __METHOD__);
                return;
            }

            $this->applyApiPayloadToPayment($payment, $payload);
            $payment->save();

            if ($payment->status === 'PAID' && $payment->commerceTransactionHash !== null) {
                $this->createCommerceTransaction($payment);
            }

            Event::trigger(self::class, self::EVENT_AFTER_PAYMENT_CALLBACK, new PaymentEvent([
                'payment' => $payment,
            ]));
        } catch (\Throwable $e) {
            SwishSuite::getInstance()->helpers->logError(
                "Payment callback failed: {$paymentId} - {$e->getMessage()}",
                __METHOD__
            );
        }
    }

    /** @param array<string, mixed> $payload */
    public function processRefundCallback(array $payload): void
    {
        $refundId = is_string($payload['id'] ?? null) ? $payload['id'] : null;
        if ($refundId === null) {
            SwishSuite::getInstance()->helpers->logWarning('Refund callback payload missing id', __METHOD__);
            return;
        }

        try {
            $refund = Refund::findByRefundId($refundId);
            if (!$refund) {
                SwishSuite::getInstance()->helpers->logWarning("Refund not found: {$refundId}", __METHOD__);
                return;
            }

            $this->applyApiPayloadToRefund($refund, $payload);
            $refund->save();

            if ($refund->status === 'PAID' && $refund->paymentRecordId !== null) {
                $this->createRefundCommerceTransaction($refund);
            }

            Event::trigger(self::class, self::EVENT_AFTER_REFUND_CALLBACK, new RefundEvent([
                'refund' => $refund,
            ]));
        } catch (\Throwable $e) {
            SwishSuite::getInstance()->helpers->logError(
                "Refund callback failed: {$refundId} - {$e->getMessage()}",
                __METHOD__
            );
        }
    }

    /** @param array<string, mixed> $payload */
    public function applyApiPayloadToPayment(Payment $payment, array $payload): void
    {
        $incomingStatus = is_string($payload['status'] ?? null) ? $payload['status'] : null;
        $statusEnum = $incomingStatus !== null ? PaymentStatus::tryFrom($incomingStatus) : null;

        if ($statusEnum !== null) {
            $payment->status = $statusEnum->value;
        }

        $payment->paymentReference = is_string($payload['paymentReference'] ?? null)
            ? $payload['paymentReference']
            : $payment->paymentReference;
        $payment->payeePaymentReference = is_string($payload['payeePaymentReference'] ?? null)
            ? $payload['payeePaymentReference']
            : $payment->payeePaymentReference;
        $payment->callbackUrl = is_string($payload['callbackUrl'] ?? null)
            ? $payload['callbackUrl']
            : $payment->callbackUrl;
        $payment->payerAlias = is_string($payload['payerAlias'] ?? null)
            ? $payload['payerAlias']
            : $payment->payerAlias;
        $payment->payeeAlias = is_string($payload['payeeAlias'] ?? null)
            ? $payload['payeeAlias']
            : $payment->payeeAlias;
        $payment->message = is_string($payload['message'] ?? null)
            ? $payload['message']
            : $payment->message;
        $payment->errorCode = is_string($payload['errorCode'] ?? null) ? $payload['errorCode'] : null;
        $payment->errorMessage = is_string($payload['errorMessage'] ?? null) ? $payload['errorMessage'] : null;

        $amountMinorUnits = SwishSuite::getInstance()->swishPayment->normalizeAmountToMinorUnits($payload['amount'] ?? null);
        if ($amountMinorUnits !== null) {
            $payment->amount = $amountMinorUnits;
        }

        $payment->setSwishResponseArray($payload);
    }

    /** @param array<string, mixed> $payload */
    public function applyApiPayloadToRefund(Refund $refund, array $payload): void
    {
        $incomingStatus = is_string($payload['status'] ?? null) ? $payload['status'] : null;
        $statusEnum = $incomingStatus !== null ? RefundStatus::tryFrom($incomingStatus) : null;

        if ($statusEnum !== null) {
            $refund->status = $statusEnum->value;
        }

        $refund->paymentReference = is_string($payload['paymentReference'] ?? null)
            ? $payload['paymentReference']
            : $refund->paymentReference;
        $refund->payeeAlias = is_string($payload['payeeAlias'] ?? null)
            ? $payload['payeeAlias']
            : $refund->payeeAlias;
        $refund->errorCode = is_string($payload['errorCode'] ?? null) ? $payload['errorCode'] : null;
        $refund->errorMessage = is_string($payload['errorMessage'] ?? null) ? $payload['errorMessage'] : null;

        $amountMinorUnits = SwishSuite::getInstance()->swishPayment->normalizeAmountToMinorUnits($payload['amount'] ?? null);
        if ($amountMinorUnits !== null) {
            $refund->amount = $amountMinorUnits;
        }

        $refund->setSwishResponseArray($payload);
    }

    private function createCommerceTransaction(Payment $payment): void
    {
        try {
            $parentTransaction = Commerce::getInstance()->transactions
                ->getTransactionByHash($payment->commerceTransactionHash);

            if (!$parentTransaction) {
                SwishSuite::getInstance()->helpers->logWarning(
                    "Commerce transaction not found for hash: {$payment->commerceTransactionHash}",
                    __METHOD__
                );
                return;
            }

            $transaction = Commerce::getInstance()->transactions
                ->createTransaction(null, $parentTransaction, TransactionRecord::TYPE_PURCHASE);

            $transaction->status = TransactionRecord::STATUS_SUCCESS;
            $transaction->reference = $payment->paymentReference ?? '';
            $transaction->code = 'PAID';
            $transaction->message = 'Payment confirmed via Swish callback.';
            $transaction->response = json_encode($payment->getSwishResponseArray());

            Commerce::getInstance()->transactions->saveTransaction($transaction);
        } catch (\Throwable $e) {
            SwishSuite::getInstance()->helpers->logError(
                "Failed to create Commerce transaction for payment {$payment->paymentId}: {$e->getMessage()}",
                __METHOD__
            );
        }
    }

    private function createRefundCommerceTransaction(Refund $refund): void
    {
        try {
            $payment = Payment::findById($refund->paymentRecordId);

            if (!$payment || $payment->commerceTransactionHash === null) {
                return;
            }

            $parentTransaction = Commerce::getInstance()->transactions
                ->getTransactionByHash($payment->commerceTransactionHash);

            if (!$parentTransaction) {
                SwishSuite::getInstance()->helpers->logWarning(
                    "Commerce transaction not found for refund hash: {$payment->commerceTransactionHash}",
                    __METHOD__
                );
                return;
            }

            $transaction = Commerce::getInstance()->transactions
                ->createTransaction(null, $parentTransaction, TransactionRecord::TYPE_REFUND);

            $transaction->status = TransactionRecord::STATUS_SUCCESS;
            $transaction->reference = $refund->paymentReference ?? '';
            $transaction->amount = $refund->amount / 100;
            $transaction->code = 'REFUNDED';
            $transaction->message = 'Refund confirmed via Swish callback.';
            $transaction->response = json_encode($refund->getSwishResponseArray());

            Commerce::getInstance()->transactions->saveTransaction($transaction);
        } catch (\Throwable $e) {
            SwishSuite::getInstance()->helpers->logError(
                "Failed to create refund transaction: {$e->getMessage()}",
                __METHOD__
            );
        }
    }
}
