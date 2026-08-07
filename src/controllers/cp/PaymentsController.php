<?php

namespace NinetyNineX\SwishSuite\controllers\cp;

use Craft;
use craft\helpers\App;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use NinetyNineX\SwishSuite\records\Payment;
use NinetyNineX\SwishSuite\services\SwishPaymentService;
use NinetyNineX\SwishSuite\SwishSuite;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class PaymentsController extends Controller
{
    private const PER_PAGE = 50;
    private const TEMPLATE_INDEX      = SwishSuite::TEMPLATE_CP_PAYMENTS_INDEX;
    private const TEMPLATE_VIEW       = SwishSuite::TEMPLATE_CP_PAYMENTS_VIEW;
    private const TEMPLATE_CREATE     = SwishSuite::TEMPLATE_CP_PAYMENTS_CREATE;
    private const ERROR_NOT_FOUND = 'Payment not found.';
    private const ERROR_INVALID_AMOUNT = 'Invalid payment amount.';
    private const ERROR_CREATE_API = 'Could not create payment via the Swish API.';
    private const ERROR_LOCAL_SAVE = 'Payment was sent to Swish but could not be saved locally. Check the logs.';
    private const ERROR_REFRESH = 'Could not refresh payment status.';
    private const ERROR_REFRESH_SAVE = 'Could not save refreshed payment status.';
    private const NOTICE_CREATED = 'Payment created successfully.';
    private const NOTICE_REFRESHED = 'Payment status refreshed.';
    private const REFERENCE_PREFIX_ORDER = 'ORDER';

    /** @var array<string, string> */
    private const DEFAULT_FORM_DATA = [
        'payerAlias' => '',
        'amount' => '',
        'reference' => '',
        'message' => '',
    ];

    public function actionIndex(): Response
    {
        $this->requireCpRequest();

        $request = Craft::$app->getRequest();
        $query = Payment::find()->orderBy(['dateCreated' => SORT_DESC]);

        $status    = $request->getQueryParam('status');
        $flow      = $request->getQueryParam('flow');
        $paymentId = $request->getQueryParam('paymentId');
        $reference = $request->getQueryParam('reference');
        $dateFrom  = $request->getQueryParam('dateFrom');
        $dateTo    = $request->getQueryParam('dateTo');

        if (is_string($status) && $status !== '') {
            $query->andWhere(['status' => $status]);
        }
        if (is_string($flow) && $flow !== '') {
            $query->andWhere(['flow' => $flow]);
        }
        if (is_string($paymentId) && $paymentId !== '') {
            $query->andWhere(['like', 'paymentId', $paymentId]);
        }
        if (is_string($reference) && $reference !== '') {
            $query->andWhere(['like', 'payeePaymentReference', $reference]);
        }
        if (is_string($dateFrom) && $dateFrom !== '') {
            $query->andWhere(['>=', 'dateCreated', $dateFrom . ' 00:00:00']);
        }
        if (is_string($dateTo) && $dateTo !== '') {
            $query->andWhere(['<=', 'dateCreated', $dateTo . ' 23:59:59']);
        }

        $totalCount = (int)$query->count();
        $totalPages = max(1, (int)ceil($totalCount / self::PER_PAGE));
        $page = max(1, min($totalPages, (int)($request->getQueryParam('page') ?? 1)));
        $payments = $query->limit(self::PER_PAGE)->offset(($page - 1) * self::PER_PAGE)->all();

        return $this->renderTemplate(self::TEMPLATE_INDEX, [
            'payments' => $payments,
            'filters' => [
                'status'    => is_string($status) ? $status : '',
                'flow'      => is_string($flow) ? $flow : '',
                'paymentId' => is_string($paymentId) ? $paymentId : '',
                'reference' => is_string($reference) ? $reference : '',
                'dateFrom'  => is_string($dateFrom) ? $dateFrom : '',
                'dateTo'    => is_string($dateTo) ? $dateTo : '',
            ],
            'page'       => $page,
            'totalPages' => $totalPages,
            'totalCount' => $totalCount,
        ]);
    }

    public function actionView(int $id): Response
    {
        $this->requireCpRequest();

        $payment = Payment::findOne($id);

        if (!$payment) {
            throw new NotFoundHttpException(self::ERROR_NOT_FOUND);
        }

        $qrCodeDataUri = null;
        $swishUrl = null;
        if (
            $payment->flow === SwishPaymentService::FLOW_MCOMMERCE
            && $payment->status === Payment::STATUS_CREATED
            && $payment->paymentRequestToken
        ) {
            $returnUrl = UrlHelper::siteUrl(SwishSuite::SITE_ROUTE_PAYMENT_SUCCESS, ['paymentId' => $payment->paymentId]);
            $swishUrl  = SwishSuite::SWISH_APP_URL . '?token=' . $payment->paymentRequestToken . '&callbackurl=' . urlencode($returnUrl);
            $qrCodeDataUri = SwishSuite::getInstance()->swishPayment->generateQrCodeDataUri($swishUrl);
        }

        return $this->renderTemplate(self::TEMPLATE_VIEW, [
            'payment'      => $payment,
            'qrCodeDataUri' => $qrCodeDataUri,
            'swishUrl' => $swishUrl,
        ]);
    }

    public function actionCreate(): Response
    {
        $this->requireCpRequest();

        return $this->renderCreateTemplate();
    }

    public function actionStore(): Response
    {
        $this->requireCpRequest();
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $amountService = SwishSuite::getInstance()->swishPayment;
        $settings = SwishSuite::getInstance()->getSettings();

        $payerAlias = trim((string)$request->getBodyParam('payerAlias'));
        $amountInput = $request->getBodyParam('amount');
        $reference = trim((string)$request->getBodyParam('reference'));
        $message = trim((string)$request->getBodyParam('message'));

        $formData = [
            'payerAlias' => $payerAlias,
            'amount' => is_scalar($amountInput) ? trim((string)$amountInput) : '',
            'reference' => $reference,
            'message' => $message,
        ];

        $amountMinorUnits = $amountService->normalizeAmountToMinorUnits(
            is_scalar($amountInput) ? (string)$amountInput : null
        );

        if ($amountMinorUnits === null) {
            Craft::$app->getSession()->setError(self::ERROR_INVALID_AMOUNT);
            return $this->renderCreateTemplate($formData);
        }

        $paymentId = $amountService->generatePaymentId();
        $callbackIdentifier = $amountService->generatePaymentId();
        $callbackUrl = UrlHelper::siteUrl(SwishSuite::getInstance()->getCallbackRoute());
        $safeReference = $amountService->generateSafeReference($reference !== '' ? $reference : self::REFERENCE_PREFIX_ORDER);
        $safeMessage = $message !== '' ? mb_substr($message, 0, 50) : null;
        $flow = $payerAlias !== '' ? SwishPaymentService::FLOW_ECOMMERCE : SwishPaymentService::FLOW_MCOMMERCE;

        $result = $amountService->createPaymentRequest(
            paymentId: $paymentId,
            amountMinorUnits: $amountMinorUnits,
            callbackUrl: $callbackUrl,
            payerAlias: $payerAlias !== '' ? $payerAlias : null,
            reference: $safeReference,
            message: $safeMessage,
            callbackIdentifier: $callbackIdentifier,
        );

        if (!$result) {
            Craft::$app->getSession()->setError(self::ERROR_CREATE_API);
            return $this->renderCreateTemplate($formData);
        }

        $payment = new Payment();
        $payment->paymentId = $paymentId;
        $payment->payeePaymentReference = $safeReference;
        $payment->callbackIdentifier = $callbackIdentifier;
        $payment->userId = Craft::$app->getUser()->getId();
        $payment->amount = $amountMinorUnits;
        $payment->currency = SwishPaymentService::CURRENCY_SEK;
        $payment->status = Payment::STATUS_CREATED;
        $payment->payerAlias = $payerAlias !== '' ? $payerAlias : null;
        $payment->payeeAlias = (string)(App::parseEnv($settings->swishNumber) ?: $settings->swishNumber);
        $payment->message = $safeMessage;
        $payment->callbackUrl = $callbackUrl;
        $payment->flow = $flow;
        $payment->paymentRequestToken = $result['paymentRequestToken'] ?? null;

        if (!$payment->save()) {
            SwishSuite::getInstance()->helpers->logError(
                'Payment created at Swish but local save failed for paymentId: ' . $paymentId,
                __METHOD__
            );
            Craft::$app->getSession()->setError(self::ERROR_LOCAL_SAVE);
            return $this->redirect(SwishSuite::CP_ROUTE_PAYMENTS);
        }

        Craft::$app->getSession()->setNotice(self::NOTICE_CREATED);

        if ($payment->flow === SwishPaymentService::FLOW_MCOMMERCE) {
            return $this->redirect(SwishSuite::CP_ROUTE_PAYMENTS . '/' . (int)$payment->id . '/qr-waiting');
        }

        return $this->redirect(SwishSuite::CP_ROUTE_PAYMENTS . '/' . (int)$payment->id);
    }

    public function actionQrWaiting(int $id): Response
    {
        $this->requireCpRequest();

        $payment = Payment::findOne($id);
        if (!$payment) {
            throw new NotFoundHttpException(self::ERROR_NOT_FOUND);
        }

        if ($payment->flow !== SwishPaymentService::FLOW_MCOMMERCE || !$payment->paymentRequestToken) {
            return $this->redirect(SwishSuite::CP_ROUTE_PAYMENTS . '/' . $id);
        }

        $returnUrl     = UrlHelper::cpUrl(SwishSuite::CP_ROUTE_PAYMENTS . '/' . $id);
        $swishUrl      = SwishSuite::SWISH_APP_URL . '?token=' . $payment->paymentRequestToken
            . '&callbackurl=' . urlencode(UrlHelper::siteUrl(SwishSuite::SITE_ROUTE_PAYMENT_SUCCESS, ['paymentId' => $payment->paymentId]));
        $qrCodeDataUri = SwishSuite::getInstance()->swishPayment->generateQrCodeDataUri($swishUrl);

        return $this->renderTemplate(SwishSuite::TEMPLATE_CP_PAYMENTS_QR_WAITING, [
            'payment'       => $payment,
            'qrCodeDataUri' => $qrCodeDataUri,
            'redirectUrl'   => $returnUrl,
        ]);
    }

    public function actionRefreshStatus(int $id): Response
    {
        $this->requireCpRequest();
        $this->requirePostRequest();

        $payment = Payment::findOne($id);
        if (!$payment) {
            throw new NotFoundHttpException(self::ERROR_NOT_FOUND);
        }

        $status = SwishSuite::getInstance()->swishPayment->getPaymentStatus($payment->paymentId);
        if ($status === null) {
            Craft::$app->getSession()->setError(self::ERROR_REFRESH);
            return $this->redirect(SwishSuite::CP_ROUTE_PAYMENTS . '/' . $id);
        }

        SwishSuite::getInstance()->callbackHandler->applyApiPayloadToPayment($payment, $status);
        if (!$payment->save()) {
            Craft::$app->getSession()->setError(self::ERROR_REFRESH_SAVE);
            return $this->redirect(SwishSuite::CP_ROUTE_PAYMENTS . '/' . $id);
        }

        Craft::$app->getSession()->setNotice(self::NOTICE_REFRESHED);

        return $this->redirect(SwishSuite::CP_ROUTE_PAYMENTS . '/' . $id);
    }

    /** @param array<string, string> $formData */
    private function renderCreateTemplate(array $formData = []): Response
    {
        return $this->renderTemplate(self::TEMPLATE_CREATE, [
            'formData' => array_merge(self::DEFAULT_FORM_DATA, $formData),
        ]);
    }
}
