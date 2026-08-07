<?php

namespace NinetyNineX\SwishSuite\queue\jobs;

use Craft;
use craft\queue\BaseJob;
use NinetyNineX\SwishSuite\records\Payment;
use NinetyNineX\SwishSuite\records\Refund;
use NinetyNineX\SwishSuite\SwishSuite;

class ProcessCallbackJob extends BaseJob
{
    /** @var array<string, mixed> */
    public array $payload = [];

    public function execute($queue): void
    {
        $entityId = is_string($this->payload['id'] ?? null) ? $this->payload['id'] : null;

        if ($entityId === null) {
            SwishSuite::getInstance()->helpers->logWarning(
                'ProcessCallbackJob: payload missing id field',
                __METHOD__
            );
            return;
        }

        try {
            $payment = Payment::find()->where(['paymentId' => $entityId])->one();
            if ($payment) {
                // Skip if already in a terminal state to prevent double-processing
                if (in_array($payment->status, [
                    Payment::STATUS_PAID,
                    Payment::STATUS_DECLINED,
                    Payment::STATUS_CANCELLED,
                    Payment::STATUS_ERROR,
                ], true)) {
                    SwishSuite::getInstance()->helpers->logInfo(
                        "Skipping duplicate callback for payment {$entityId} already in terminal status {$payment->status}",
                        __METHOD__
                    );
                    return;
                }
                SwishSuite::getInstance()->callbackHandler->processPaymentCallback($this->payload);
                return;
            }

            $refund = Refund::find()->where(['refundId' => $entityId])->one();
            if ($refund) {
                // Skip if already in a terminal state
                if (in_array($refund->status, [
                    Refund::STATUS_PAID,
                    Refund::STATUS_DECLINED,
                    Refund::STATUS_ERROR,
                ], true)) {
                    SwishSuite::getInstance()->helpers->logInfo(
                        "Skipping duplicate callback for refund {$entityId} already in terminal status {$refund->status}",
                        __METHOD__
                    );
                    return;
                }
                SwishSuite::getInstance()->callbackHandler->processRefundCallback($this->payload);
                return;
            }

            SwishSuite::getInstance()->helpers->logWarning(
                "ProcessCallbackJob: no payment or refund found for id {$entityId}",
                __METHOD__
            );
        } catch (\Exception $exception) {
            SwishSuite::getInstance()->helpers->logError(
                'Error processing callback job: ' . $exception->getMessage(),
                __METHOD__
            );
            throw $exception;
        }
    }

    protected function defaultDescription(): ?string
    {
        $eventType = is_string($this->payload['eventType'] ?? null)
            ? $this->payload['eventType']
            : 'unknown';

        return Craft::t('swish-suite', 'Process Swish callback: {eventType}', [
            'eventType' => $eventType,
        ]);
    }
}
