<?php

namespace NinetyNineX\SwishSuite\controllers;

use Craft;
use craft\helpers\App;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use NinetyNineX\SwishSuite\events\PaymentEvent;
use NinetyNineX\SwishSuite\records\Payment;
use NinetyNineX\SwishSuite\services\SwishPaymentService;
use NinetyNineX\SwishSuite\SwishSuite;
use yii\base\Event;
use yii\web\Response;

class PaymentsController extends Controller
{
    private const CHECKOUT_TEMPLATE = SwishSuite::TEMPLATE_CHECKOUT_INDEX;
    private const WAITING_TEMPLATE  = SwishSuite::TEMPLATE_CHECKOUT_WAITING;
    private const ERROR_INVALID_AMOUNT = 'Invalid Swish amount.';
    private const ERROR_START_PAYMENT  = 'Failed to start the Swish payment.';
    private const REFERENCE_PREFIX_ORDER = 'ORDER';

    protected array|int|bool $allowAnonymous = true;

    public function actionCheckout(): Response
    {
        $settings = SwishSuite::getInstance()->getSettings();
        $request  = Craft::$app->getRequest();

        return $this->renderTemplate(self::CHECKOUT_TEMPLATE, [
            'settings'   => $settings,
            'amount'     => $request->getQueryParam('amount'),
            'reference'  => $request->getQueryParam('reference'),
            'message'    => $request->getQueryParam('message'),
            'lockAmount' => (bool)$request->getQueryParam('lock'),
        ]);
    }

    public function actionPay(): Response
    {
        $request   = Craft::$app->getRequest();
        $amountRaw = is_scalar($request->getQueryParam('amount')) ? (string)$request->getQueryParam('amount') : '';
        $message   = trim((string)($request->getQueryParam('message') ?? ''));

        $amountMinorUnits = SwishSuite::getInstance()->swishPayment->normalizeAmountToMinorUnits(
            $amountRaw !== '' ? $amountRaw : null
        );

        return $this->renderTemplate('swish-suite/checkout/pay', [
            'amount'           => $amountRaw,
            'amountMinorUnits' => $amountMinorUnits,
            'message'          => $message,
        ]);
    }

    public function actionProcess(): Response
    {
        $this->requirePostRequest();

        $request  = Craft::$app->getRequest();
        $settings = SwishSuite::getInstance()->getSettings();
        $amountService = SwishSuite::getInstance()->swishPayment;

        $amountInput = $request->getBodyParam('amount');
        $payerAlias  = $request->getBodyParam('payerAlias');
        $message     = $request->getBodyParam('message');
        $reference   = $request->getBodyParam('reference');
        $flow        = $payerAlias ? SwishPaymentService::FLOW_ECOMMERCE : SwishPaymentService::FLOW_MCOMMERCE;
        $amountMinorUnits = $amountService->normalizeAmountToMinorUnits(is_scalar($amountInput) ? (string)$amountInput : null);

        if ($amountMinorUnits === null) {
            Craft::$app->session->setError(self::ERROR_INVALID_AMOUNT);
            return $this->redirectToPostedUrl();
        }

        $paymentId          = $amountService->generatePaymentId();
        $callbackIdentifier = $amountService->generatePaymentId();
        $callbackUrl        = UrlHelper::siteUrl(SwishSuite::getInstance()->getCallbackRoute());
        $safeReference      = $amountService->generateSafeReference(
            is_string($reference) && $reference !== '' ? $reference : self::REFERENCE_PREFIX_ORDER
        );

        try {
            $result = $amountService->createPaymentRequest(
                paymentId:          $paymentId,
                amountMinorUnits:   $amountMinorUnits,
                callbackUrl:        $callbackUrl,
                payerAlias:         $payerAlias ?: null,
                reference:          $safeReference,
                message:            $message,
                callbackIdentifier: $callbackIdentifier,
            );

            if (!$result) {
                Craft::$app->session->setError(self::ERROR_START_PAYMENT);
                return $this->redirectToPostedUrl();
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
                'Payment creation failed: ' . $errorMessage . ' (' . $e->getMessage() . ')',
                __METHOD__
            );

            Craft::$app->session->setError($errorMessage);
            return $this->redirectToPostedUrl();
        }

        $payment                        = new Payment();
        $payment->paymentId             = $paymentId;
        $payment->payeePaymentReference = $safeReference;
        $payment->callbackIdentifier    = $callbackIdentifier;
        $payment->userId                = Craft::$app->getUser()->getId();
        $payment->amount                = $amountMinorUnits;
        $payment->currency              = SwishPaymentService::CURRENCY_SEK;
        $payment->status                = Payment::STATUS_CREATED;
        $payment->payerAlias            = $payerAlias;
        $payment->payeeAlias            = (string)(App::parseEnv($settings->swishNumber) ?: $settings->swishNumber);
        $payment->message               = $message;
        $payment->callbackUrl           = $callbackUrl;
        $payment->flow                  = $flow;
        $payment->paymentRequestToken   = $result['paymentRequestToken'] ?? null;

        Event::trigger(SwishPaymentService::class, SwishPaymentService::EVENT_BEFORE_PAYMENT_CREATED, new PaymentEvent([
            'payment' => $payment,
        ]));

        $payment->save();

        Event::trigger(SwishPaymentService::class, SwishPaymentService::EVENT_AFTER_PAYMENT_CREATED, new PaymentEvent([
            'payment' => $payment,
        ]));

        // M-Commerce: always show QR code
        // Post/Redirect/Get: redirect to a GET route so refreshing doesn't trigger "Method not allowed".
        return $this->redirect(UrlHelper::siteUrl(SwishSuite::SITE_ROUTE_PAYMENT_WAITING, [
            'paymentId' => $paymentId,
        ]));
    }

    public function actionWaiting(): Response
    {
        $request   = Craft::$app->getRequest();
        $paymentId = $request->getQueryParam('paymentId');

        if (!is_string($paymentId) || $paymentId === '') {
            return $this->asJson(['error' => 'Missing paymentId'])->setStatusCode(400);
        }

        // Sanitize returnUrl / cancelUrl to prevent open redirect to external domains.
        $returnUrl = $request->getQueryParam('returnUrl');
        if (is_string($returnUrl) && (str_contains($returnUrl, '://') || str_starts_with($returnUrl, '//'))) {
            $returnUrl = null;
        }
        $cancelUrl = $request->getQueryParam('cancelUrl');
        if (is_string($cancelUrl) && (str_contains($cancelUrl, '://') || str_starts_with($cancelUrl, '//'))) {
            $cancelUrl = null;
        }

        $payment = Payment::find()->where(['paymentId' => $paymentId])->one();

        // Best-effort render; the POST should have created the record, but don't fatal if not.
        $flow = $payment?->flow ?? SwishPaymentService::FLOW_ECOMMERCE;

        $vars = [
            'paymentId' => $paymentId,
            'flow'      => $flow,
        ];

        if ($payment && $payment->paymentRequestToken) {
            $returnUrl = UrlHelper::siteUrl(SwishSuite::SITE_ROUTE_PAYMENT_SUCCESS, ['paymentId' => $paymentId]);
            $swishUrl  = SwishSuite::SWISH_APP_URL . '?token=' . $payment->paymentRequestToken . '&callbackurl=' . urlencode($returnUrl);
            $vars['swishUrl'] = $swishUrl;

            if ($flow === SwishPaymentService::FLOW_MCOMMERCE) {
                $vars['qrCodeDataUri'] = $this->generateSwishQrCode($swishUrl);
            }
        }

        return $this->renderTemplate(self::WAITING_TEMPLATE, $vars);
    }

    public function actionPollStatus(): Response
    {
        $paymentId = Craft::$app->getRequest()->getQueryParam('paymentId');

        if (!is_string($paymentId) || $paymentId === '') {
            return $this->asJson(['status' => 'UNKNOWN']);
        }

        $payment = Payment::find()->where(['paymentId' => $paymentId])->one();

        if ($payment && !$payment->isInTerminalState()) {
            // Callbacks from Swish may not reach the server (e.g. local dev).
            // Always consult the Swish API on every poll so the page updates
            // as soon as the payment is confirmed, regardless of callback delivery.
            // We only persist when the status actually changes to avoid resetting
            // dateUpdated on every poll (which was causing a permanent 5-second
            // blind window where the old shouldSync throttle never triggered).
            try {
                $apiStatus = SwishSuite::getInstance()->swishPayment->getPaymentStatus($paymentId);

                if (is_array($apiStatus) && $apiStatus !== []) {
                    $previousStatus = $payment->status;
                    SwishSuite::getInstance()->callbackHandler->applyApiPayloadToPayment($payment, $apiStatus);

                    if ($payment->status !== $previousStatus) {
                        $payment->save();
                    }
                } else {
                    SwishSuite::getInstance()->helpers->logWarning(
                        "Poll: getPaymentStatus returned empty/null for {$paymentId}",
                        __METHOD__
                    );
                }
            } catch (\Throwable $e) {
                SwishSuite::getInstance()->helpers->logWarning(
                    "Poll: API error for {$paymentId}: " . $e->getMessage(),
                    __METHOD__
                );
            }
        }

        return $this->asJson([
            'status' => $payment?->status ?? 'UNKNOWN',
        ]);
    }

    public function actionSuccess(): Response
    {
        $paymentId = Craft::$app->request->getQueryParam('paymentId');
        $payment   = Payment::find()->where(['paymentId' => $paymentId])->one();

        if ($payment && !$payment->isInTerminalState()) {
            $status = SwishSuite::getInstance()->swishPayment->getPaymentStatus($paymentId);
            if ($status) {
                SwishSuite::getInstance()->callbackHandler->applyApiPayloadToPayment($payment, $status);
                $payment->save();
            }
        }

        return $this->redirect(SwishSuite::getInstance()->getSettings()->successUrl);
    }

    public function actionCancel(): Response
    {
        $paymentId = Craft::$app->request->getQueryParam('paymentId');
        $payment   = Payment::find()->where(['paymentId' => $paymentId])->one();

        if ($payment && $payment->status === Payment::STATUS_CREATED) {
            SwishSuite::getInstance()->swishPayment->cancelPayment($paymentId);
            $payment->status = Payment::STATUS_CANCELLED;
            $payment->save();
        }

        return $this->redirect(SwishSuite::getInstance()->getSettings()->cancelUrl);
    }

    private function generateSwishQrCode(string $data): string
    {
        return SwishSuite::getInstance()->swishPayment->generateQrCodeDataUri($data);
    }
}
