<?php

namespace NinetyNineX\SwishSuite\console\controllers;

use craft\console\Controller;
use NinetyNineX\SwishSuite\enums\PaymentStatus;
use NinetyNineX\SwishSuite\records\Payment;
use NinetyNineX\SwishSuite\SwishSuite;
use yii\console\ExitCode;

/**
 * Manage Swish payments from the CLI.
 */
class PaymentsController extends Controller
{
    /**
     * Reconcile payments stuck in CREATED status by querying the Swish API.
     *
     * Finds all payments that have been in CREATED status for more than 3 minutes
     * (the Swish payment expiry window) and fetches their real status from the API.
     *
     * Usage:
     *   craft swish-suite/payments/sync
     */
    public function actionSync(): int
    {
        $threshold = (new \DateTime())->modify('-3 minutes');

        /** @var Payment[] $payments */
        $payments = Payment::find()
            ->where(['status' => PaymentStatus::Created->value])
            ->andWhere(['<', 'dateCreated', $threshold->format('Y-m-d H:i:s')])
            ->orderBy(['dateCreated' => SORT_ASC])
            ->all();

        if (empty($payments)) {
            $this->stdout("No stuck payments found.\n");
            return ExitCode::OK;
        }

        $this->stdout(sprintf("Found %d stuck payment(s). Syncing...\n", count($payments)));

        $updated = 0;
        $failed  = 0;

        foreach ($payments as $payment) {
            $apiStatus = SwishSuite::getInstance()->swishPayment->getPaymentStatus($payment->paymentId);

            if ($apiStatus === null) {
                $this->stdout("  [{$payment->paymentId}] API returned null — skipped.\n");
                $failed++;
                continue;
            }

            $prevStatus = $payment->status;
            SwishSuite::getInstance()->callbackHandler->applyApiPayloadToPayment($payment, $apiStatus);

            if (!$payment->save()) {
                $this->stdout("  [{$payment->paymentId}] Save failed — skipped.\n");
                $failed++;
                continue;
            }

            $this->stdout("  [{$payment->paymentId}] {$prevStatus} → {$payment->status}\n");
            $updated++;
        }

        $this->stdout(sprintf("\nDone. Updated: %d, Failed: %d.\n", $updated, $failed));

        return $failed > 0 ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }
}
