<?php

namespace NinetyNineX\SwishSuite\controllers\cp;

use Craft;
use craft\helpers\App;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use NinetyNineX\SwishSuite\events\RefundEvent;
use NinetyNineX\SwishSuite\records\Payment;
use NinetyNineX\SwishSuite\records\Refund;
use NinetyNineX\SwishSuite\services\SwishPaymentService;
use NinetyNineX\SwishSuite\SwishSuite;
use yii\base\Event;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class RefundsController extends Controller
{
    private const TEMPLATE_INDEX  = SwishSuite::TEMPLATE_CP_REFUNDS_INDEX;
    private const TEMPLATE_VIEW   = SwishSuite::TEMPLATE_CP_REFUNDS_VIEW;
    private const TEMPLATE_CREATE = SwishSuite::TEMPLATE_CP_REFUNDS_CREATE;
    private const ERROR_REFUND_NOT_FOUND       = 'Refund not found.';
    private const ERROR_PAYMENT_NOT_SPECIFIED  = 'Payment not specified.';
    private const ERROR_PAYMENT_NOT_FOUND      = 'Payment not found.';
    private const ERROR_INVALID_PAYMENT_STATE  = 'Refunds can only be created for PAID payments with a valid payment reference.';
    private const ERROR_PAYMENT_TOO_OLD        = 'Refunds are only allowed within 13 months of the original payment.';
    private const ERROR_INVALID_PAYMENT_FOR_STORE = 'Cannot create refund: payment is not PAID or lacks a payment reference.';
    private const ERROR_INVALID_AMOUNT         = 'Invalid refund amount.';
    private const ERROR_AMOUNT_EXCEEDS_AVAILABLE = 'Refund amount exceeds the available refundable amount.';
    private const ERROR_CREATE_API             = 'Could not create refund via Swish API.';
    private const ERROR_LOCAL_SAVE             = 'Refund was sent to Swish but could not be saved locally. Check logs.';
    private const ERROR_REFRESH                = 'Could not refresh refund status from Swish.';
    private const ERROR_REFRESH_SAVE           = 'Could not save refreshed refund status.';
    private const NOTICE_CREATED               = 'Refund created successfully.';
    private const NOTICE_REFRESHED             = 'Refund status refreshed.';
    private const REFUND_WINDOW_INTERVAL       = '-13 months';

    public function actionIndex(): Response
    {
        $this->requireCpRequest();

        $refunds = Refund::find()->orderBy(['dateCreated' => SORT_DESC])->all();

        return $this->renderTemplate(self::TEMPLATE_INDEX, [
            'refunds' => $refunds,
        ]);
    }

    public function actionView(int $id): Response
    {
        $this->requireCpRequest();

        $refund = Refund::findOne($id);

        if (!$refund) {
            throw new NotFoundHttpException(self::ERROR_REFUND_NOT_FOUND);
        }

        return $this->renderTemplate(self::TEMPLATE_VIEW, [
            'refund' => $refund,
        ]);
    }

    public function actionCreate(): Response
    {
        $this->requireCpRequest();

        $request   = Craft::$app->getRequest();
        $paymentId = $request->getQueryParam('paymentId');

        if (!is_string($paymentId) || $paymentId === '') {
            throw new NotFoundHttpException(self::ERROR_PAYMENT_NOT_SPECIFIED);
        }

        // C1: lookup by paymentId UUID — avoids exposing sequential integer PKs in URLs.
        $payment = Payment::find()->where(['paymentId' => $paymentId])->one();

        if (!$payment) {
            throw new NotFoundHttpException(self::ERROR_PAYMENT_NOT_FOUND);
        }

        if ($payment->status !== Payment::STATUS_PAID || !$payment->paymentReference) {
            Craft::$app->getSession()->setError(self::ERROR_INVALID_PAYMENT_STATE);
            return $this->redirect(SwishSuite::CP_ROUTE_PAYMENTS . '/' . (int)$payment->id);
        }

        $dateCreated = $this->parseDateTime($payment->dateCreated);
        if ($dateCreated !== null && $dateCreated < (new \DateTime())->modify(self::REFUND_WINDOW_INTERVAL)) {
            Craft::$app->getSession()->setError(self::ERROR_PAYMENT_TOO_OLD);
            return $this->redirect(SwishSuite::CP_ROUTE_PAYMENTS . '/' . (int)$payment->id);
        }

        $alreadyRefundedMinorUnits = (int)(Refund::find()
            ->where(['paymentRecordId' => $payment->id])
            ->andWhere(['not', ['status' => Refund::STATUS_ERROR]])
            ->sum('amount') ?? 0);

        $availableMinorUnits = max(0, $payment->amount - $alreadyRefundedMinorUnits);

        return $this->renderTemplate(self::TEMPLATE_CREATE, [
            'payment'             => $payment,
            'availableMinorUnits' => $availableMinorUnits,
        ]);
    }

    public function actionStore(): Response
    {
        $this->requireCpRequest();
        $this->requirePostRequest();

        $request         = Craft::$app->getRequest();
        $paymentRecordId = $request->getBodyParam('paymentRecordId');
        $amountInput     = $request->getBodyParam('amount');
        $message         = $request->getBodyParam('message');

        $payment = Payment::findOne((int)$paymentRecordId);

        if (!$payment) {
            Craft::$app->getSession()->setError(self::ERROR_PAYMENT_NOT_FOUND);
            return $this->redirectToPostedUrl();
        }

        if ($payment->status !== Payment::STATUS_PAID || !$payment->paymentReference) {
            Craft::$app->getSession()->setError(self::ERROR_INVALID_PAYMENT_FOR_STORE);
            return $this->redirect(SwishSuite::CP_ROUTE_PAYMENTS . '/' . (int)$payment->id);
        }

        $dateCreated = $this->parseDateTime($payment->dateCreated);
        if ($dateCreated !== null && $dateCreated < (new \DateTime())->modify(self::REFUND_WINDOW_INTERVAL)) {
            Craft::$app->getSession()->setError(self::ERROR_PAYMENT_TOO_OLD);
            return $this->redirect(SwishSuite::CP_ROUTE_PAYMENTS . '/' . (int)$payment->id);
        }

        $amountService    = SwishSuite::getInstance()->swishPayment;
        $amountMinorUnits = $amountService->normalizeAmountToMinorUnits(
            is_scalar($amountInput) ? (string)$amountInput : null
        );

        if ($amountMinorUnits === null || $amountMinorUnits <= 0) {
            Craft::$app->getSession()->setError(self::ERROR_INVALID_AMOUNT);
            return $this->redirect(SwishSuite::CP_ROUTE_REFUNDS_CREATE . '?paymentId=' . $payment->paymentId);
        }

        $alreadyRefundedMinorUnits = (int)(Refund::find()
            ->where(['paymentRecordId' => $payment->id])
            ->andWhere(['not', ['status' => Refund::STATUS_ERROR]])
            ->sum('amount') ?? 0);

        if ($amountMinorUnits > ($payment->amount - $alreadyRefundedMinorUnits)) {
            Craft::$app->getSession()->setError(self::ERROR_AMOUNT_EXCEEDS_AVAILABLE);
            return $this->redirect(SwishSuite::CP_ROUTE_REFUNDS_CREATE . '?paymentId=' . $payment->paymentId);
        }

        $settings           = SwishSuite::getInstance()->getSettings();
        $refundId           = $amountService->generatePaymentId();
        $callbackIdentifier = $amountService->generatePaymentId();
        $callbackUrl        = UrlHelper::siteUrl(SwishSuite::getInstance()->getCallbackRoute());
        $safeMessage        = is_string($message) && $message !== '' ? substr(trim($message), 0, 50) : null;

        try {
            $result = $amountService->createRefund(
                refundId:                 $refundId,
                originalPaymentReference: (string)$payment->paymentReference,
                amountMinorUnits:         $amountMinorUnits,
                callbackUrl:              $callbackUrl,
                message:                  $safeMessage,
                callbackIdentifier:       $callbackIdentifier,
            );

            if (!$result) {
                Craft::$app->getSession()->setError(self::ERROR_CREATE_API);
                return $this->redirect(SwishSuite::CP_ROUTE_REFUNDS_CREATE . '?paymentId=' . $payment->paymentId);
            }
        } catch (\Throwable $e) {
            $errorHandler = SwishSuite::getInstance()->errorHandler;
            $errorCode = $errorHandler->detectErrorCode($e);
            $errorMessage = $errorHandler->getErrorMessage($errorCode, [
                'callbackUrl' => $callbackUrl,
                'certPath' => $settings->certPath,
                'caPath' => $settings->caPath,
            ]);

            SwishSuite::getInstance()->helpers->logError(
                'Refund creation failed: ' . $errorMessage . ' (' . $e->getMessage() . ')',
                __METHOD__
            );

            Craft::$app->getSession()->setError($errorMessage);
            return $this->redirect(SwishSuite::CP_ROUTE_REFUNDS_CREATE . '?paymentId=' . $payment->paymentId);
        }
        $refund          = new Refund();
        $refund->refundId                 = $refundId;
        $refund->paymentRecordId          = (int)$payment->id;
        $refund->originalPaymentReference = (string)$payment->paymentReference;
        $refund->callbackUrl              = $callbackUrl;
        $refund->callbackIdentifier       = $callbackIdentifier;
        $refund->payerAlias               = (string)(App::parseEnv($settings->swishNumber) ?: $settings->swishNumber);
        $refund->amount                   = $amountMinorUnits;
        $refund->currency                 = SwishPaymentService::CURRENCY_SEK;
        $refund->message                  = $safeMessage;
        $refund->status                   = Refund::STATUS_CREATED;

        Event::trigger(SwishPaymentService::class, SwishPaymentService::EVENT_BEFORE_REFUND_CREATED, new RefundEvent([
            'refund' => $refund,
        ]));

        if (!$refund->save()) {
            SwishSuite::getInstance()->helpers->logError(
                'Refund created at Swish but local save failed for refundId: ' . $refundId,
                __METHOD__
            );
            Craft::$app->getSession()->setError(self::ERROR_LOCAL_SAVE);
            return $this->redirect(SwishSuite::CP_ROUTE_REFUNDS);
        }

        Event::trigger(SwishPaymentService::class, SwishPaymentService::EVENT_AFTER_REFUND_CREATED, new RefundEvent([
            'refund' => $refund,
        ]));

        Craft::$app->getSession()->setNotice(self::NOTICE_CREATED);

        return $this->redirect(SwishSuite::CP_ROUTE_REFUNDS . '/' . (int)$refund->id);
    }

    public function actionRefreshStatus(int $id): Response
    {
        $this->requireCpRequest();
        $this->requirePostRequest();

        $refund = Refund::findOne($id);

        if (!$refund) {
            throw new NotFoundHttpException(self::ERROR_REFUND_NOT_FOUND);
        }

        $status = SwishSuite::getInstance()->swishPayment->getRefundStatus($refund->refundId);

        if ($status === null) {
            Craft::$app->getSession()->setError(self::ERROR_REFRESH);
            return $this->redirect(SwishSuite::CP_ROUTE_REFUNDS . '/' . $id);
        }

        SwishSuite::getInstance()->callbackHandler->applyApiPayloadToRefund($refund, $status);

        if (!$refund->save()) {
            Craft::$app->getSession()->setError(self::ERROR_REFRESH_SAVE);
            return $this->redirect(SwishSuite::CP_ROUTE_REFUNDS . '/' . $id);
        }

        Craft::$app->getSession()->setNotice(self::NOTICE_REFRESHED);

        return $this->redirect(SwishSuite::CP_ROUTE_REFUNDS . '/' . $id);
    }

    /**
     * Normalises a dateCreated value (DateTime, DateTimeImmutable, or ISO string)
     * to a mutable DateTime, returning null for any unrecognised input.
     * This prevents the 13-month refund window check from being silently bypassed
     * when ActiveRecord returns a string instead of a DateTime object.
     */
    private function parseDateTime(mixed $value): ?\DateTime
    {
        if ($value instanceof \DateTime) {
            return $value;
        }

        if ($value instanceof \DateTimeImmutable) {
            return \DateTime::createFromImmutable($value);
        }

        if (is_string($value) && $value !== '') {
            $dt = \DateTime::createFromFormat('Y-m-d H:i:s', $value)
                ?: \DateTime::createFromFormat(\DateTime::ATOM, $value)
                ?: date_create($value);

            return $dt instanceof \DateTime ? $dt : null;
        }

        return null;
    }
}
