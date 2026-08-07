<?php

namespace NinetyNineX\SwishSuite\gateways;

use Craft;
use craft\commerce\base\Gateway;
use craft\commerce\base\RequestResponseInterface;
use craft\commerce\errors\NotImplementedException;
use craft\commerce\models\payments\BasePaymentForm;
use craft\commerce\models\PaymentSource;
use craft\commerce\models\Transaction;
use craft\commerce\Plugin as Commerce;
use craft\commerce\records\Transaction as TransactionRecord;
use craft\elements\conditions\ElementConditionInterface;
use craft\helpers\App;
use craft\helpers\UrlHelper;
use craft\web\Response as WebResponse;
use yii\base\NotSupportedException;
use NinetyNineX\SwishSuite\models\SwishPaymentForm;
use NinetyNineX\SwishSuite\records\Payment;
use NinetyNineX\SwishSuite\records\Refund;
use NinetyNineX\SwishSuite\services\SwishPaymentService;
use NinetyNineX\SwishSuite\SwishSuite;

class SwishGateway extends Gateway
{
    // ── Gateway Settings (per-instance overrides for global plugin settings) ─

    public string $swishNumber  = '';
    public string $certPath     = '';
    public string $certPassword = '';
    public string $caPath       = '';
    public ?bool  $testMode     = null; // null = inherit from plugin settings
    public string $checkoutTitle = '';

    // ── Capabilities ─────────────────────────────────────────────────────────

    public function supportsPurchase(): bool          { return true; }
    public function supportsRefund(): bool            { return true; }
    public function supportsPartialRefund(): bool     { return true; }
    public function supportsAuthorize(): bool         { return false; }
    public function supportsCapture(): bool           { return false; }
    public function supportsPaymentSources(): bool    { return false; }
    public function supportsCompletePurchase(): bool  { return false; }
    public function supportsCompleteAuthorize(): bool { return false; }

    // Swish defines callback URLs programmatically per-payment — not via a fixed
    // dashboard endpoint. Declaring false prevents Commerce from showing a useless
    // webhook URL in gateway settings.
    public function supportsWebhooks(): bool { return false; }

    // Commerce 5 stores address conditions as null when unset. The base setter
    // does not accept null, so we guard here to prevent a fatal error on load.
    public function setBillingAddressCondition(ElementConditionInterface|array|string|null $condition): void
    {
        if ($condition !== null) {
            parent::setBillingAddressCondition($condition);
        }
    }

    public function setShippingAddressCondition(ElementConditionInterface|array|string|null $condition): void
    {
        if ($condition !== null) {
            parent::setShippingAddressCondition($condition);
        }
    }

    // ── Lifecycle ─────────────────────────────────────────────────────────────

    public function init(): void
    {
        parent::init();
        $this->registerCallbackListeners();
    }

    private function registerCallbackListeners(): void
    {
        // Callbacks are now handled directly by CallbackHandlerService
        // which creates Commerce transactions. No duplicate handling needed here.
    }

    // ── Purchase ──────────────────────────────────────────────────────────────

    public function purchase(Transaction $transaction, BasePaymentForm $form): RequestResponseInterface
    {
        /** @var SwishPaymentForm $form */
        $service        = SwishSuite::getInstance()->swishPayment;
        $globalSettings = SwishSuite::getInstance()->getSettings();

        $amountMinorUnits = $service->normalizeAmountToMinorUnits((string)$transaction->paymentAmount);
        if ($amountMinorUnits === null) {
            return SwishRequestResponse::asFailure('Invalid payment amount.');
        }

        $payerAlias = ($form->payerAlias !== null && $form->payerAlias !== '') ? $form->payerAlias : null;
        $flow       = $payerAlias ? SwishPaymentService::FLOW_ECOMMERCE : SwishPaymentService::FLOW_MCOMMERCE;

        $paymentId          = $service->generatePaymentId();
        $callbackIdentifier = $service->generatePaymentId();
        $callbackUrl        = UrlHelper::siteUrl(SwishSuite::getInstance()->getCallbackRoute());

        $order        = $transaction->getOrder();
        $orderRef     = $order?->reference ?? 'ORDER';
        $safeReference = $service->generateSafeReference($orderRef);

        $result = $service->createPaymentRequest(
            paymentId:          $paymentId,
            amountMinorUnits:   $amountMinorUnits,
            callbackUrl:        $callbackUrl,
            payerAlias:         $payerAlias,
            reference:          $safeReference,
            message:            $order?->reference ?? '',
            callbackIdentifier: $callbackIdentifier,
        );

        if (!$result) {
            return SwishRequestResponse::asFailure('Failed to initiate Swish payment request.');
        }

        $payment                          = new Payment();
        $payment->paymentId               = $paymentId;
        $payment->payeePaymentReference   = $safeReference;
        $payment->callbackIdentifier      = $callbackIdentifier;
        $payment->userId                  = $order?->getCustomer()?->id;
        $payment->amount                  = $amountMinorUnits;
        $payment->currency                = SwishPaymentService::CURRENCY_SEK;
        $payment->status                  = Payment::STATUS_CREATED;
        $payment->payerAlias              = $payerAlias;
        $payment->payeeAlias              = $this->resolveSwishNumber();
        $payment->message                 = $order?->reference ?? '';
        $payment->callbackUrl             = $callbackUrl;
        $payment->flow                    = $flow;
        $payment->paymentRequestToken     = $result['paymentRequestToken'] ?? null;
        $payment->commerceTransactionHash = $transaction->hash; // ← the link between systems

        if (!$payment->save()) {
            return SwishRequestResponse::asFailure('Failed to save payment record.');
        }

        // Build waiting-screen URL with Commerce return URLs so the frontend knows
        // where to redirect after PAID / DECLINED without hardcoding plugin routes.
        $returnUrl = $order?->returnUrl ?? '/';
        $cancelUrl = $order?->cancelUrl ?? '/shop/cart';

        $waitingUrl = UrlHelper::siteUrl(SwishSuite::SITE_ROUTE_PAYMENT_WAITING, [
            'paymentId' => $paymentId,
            'returnUrl' => $returnUrl,
            'cancelUrl' => $cancelUrl,
        ]);

        return SwishRequestResponse::asRedirect($waitingUrl, $paymentId);
    }

    public function authorize(Transaction $transaction, BasePaymentForm $form): RequestResponseInterface
    {
        throw new NotSupportedException('Swish does not support authorize.');
    }

    public function capture(Transaction $transaction, string $reference): RequestResponseInterface
    {
        throw new NotSupportedException('Swish does not support capture.');
    }

    public function completeAuthorize(Transaction $transaction): RequestResponseInterface
    {
        throw new NotSupportedException('Swish does not support completeAuthorize.');
    }

    public function completePurchase(Transaction $transaction): RequestResponseInterface
    {
        // Swish payments are confirmed via server-to-server callback, not by redirect.
        return SwishRequestResponse::asFailure(
            'Swish does not use synchronous payment completion.',
            'NOT_SUPPORTED'
        );
    }

    public function createPaymentSource(BasePaymentForm $sourceData, int $customerId): PaymentSource
    {
        throw new NotSupportedException('Swish does not support payment sources.');
    }

    public function deletePaymentSource(string $token): bool
    {
        throw new NotSupportedException('Swish does not support payment sources.');
    }

    public function processWebHook(): WebResponse
    {
        throw new NotImplementedException('Swish uses per-payment callbacks, not a fixed webhook endpoint.');
    }

    // ── Refund ────────────────────────────────────────────────────────────────

    public function refund(Transaction $transaction): RequestResponseInterface
    {
        // Retrieve the original paymentId stored as `reference` on the parent transaction
        $paymentId = null;
        if ($transaction->parentId) {
            $parent    = Commerce::getInstance()->transactions->getTransactionById($transaction->parentId);
            $paymentId = $parent?->reference;
        }
        if (!$paymentId) {
            $paymentId = $transaction->reference;
        }

        if (!$paymentId) {
            return SwishRequestResponse::asFailure('Cannot find original payment ID for refund.');
        }

        $payment = Payment::find()->where(['paymentId' => $paymentId])->one();

        if (!$payment) {
            return SwishRequestResponse::asFailure("Payment record not found: {$paymentId}");
        }

        if ($payment->status !== Payment::STATUS_PAID || !$payment->paymentReference) {
            return SwishRequestResponse::asFailure(
                'Can only refund a confirmed payment with a valid payment reference.'
            );
        }

        $existingRefundsTotal = (int)(Refund::find()
            ->where(['paymentRecordId' => $payment->id])
            ->andWhere(['status' => Refund::STATUS_PAID])
            ->sum('amount') ?? 0);

        $refundAmountOre = (int)round($transaction->amount * 100);
        $availableOre    = $payment->amount - $existingRefundsTotal;

        if ($refundAmountOre > $availableOre) {
            return SwishRequestResponse::asFailure(
                sprintf(
                    'Refund amount (%d öre) exceeds available balance (%d öre).',
                    $refundAmountOre,
                    $availableOre
                )
            );
        }

        $service            = SwishSuite::getInstance()->swishPayment;
        $refundId           = $service->generatePaymentId();
        $callbackIdentifier = $service->generatePaymentId();
        $callbackUrl        = UrlHelper::siteUrl(SwishSuite::getInstance()->getCallbackRoute());

        $result = $service->createRefund(
            refundId:                  $refundId,
            originalPaymentReference:  $payment->paymentReference,
            amountMinorUnits:          $refundAmountOre,
            callbackUrl:               $callbackUrl,
            message:                   'Refund via Craft Commerce',
            callbackIdentifier:        $callbackIdentifier,
        );

        if (!$result) {
            return SwishRequestResponse::asFailure('Swish API rejected the refund request.');
        }

        $refund                          = new Refund();
        $refund->refundId                = $refundId;
        $refund->paymentRecordId         = (int)$payment->id;
        $refund->originalPaymentReference = $payment->paymentReference;
        $refund->callbackIdentifier      = $callbackIdentifier;
        $refund->amount                  = $refundAmountOre;
        $refund->currency                = SwishPaymentService::CURRENCY_SEK;
        $refund->status                  = Refund::STATUS_CREATED;
        $refund->callbackUrl             = $callbackUrl;
        $refund->payeeAlias              = $payment->payeeAlias;
        $refund->save();

        // Return success immediately — Commerce records the refund transaction.
        // The definitive confirmation arrives via the Swish refund callback.
        return SwishRequestResponse::asSuccess($refundId, 'Refund request submitted to Swish.');
    }

    // ── Settings & form rendering ─────────────────────────────────────────────

    public function getSettingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate(
            'swish-suite/cp/gateways/_settings',
            ['gateway' => $this]
        );
    }

    public function getPaymentFormHtml(array $params): ?string
    {
        return Craft::$app->getView()->renderTemplate(
            'swish-suite/payment-form',
            array_merge($params, ['gateway' => $this])
        );
    }

    public function getPaymentFormModel(): BasePaymentForm
    {
        return new SwishPaymentForm();
    }

    // ── Internal helpers ──────────────────────────────────────────────────────

    private function resolveSwishNumber(): string
    {
        if ($this->swishNumber !== '') {
            return (string)(App::parseEnv($this->swishNumber) ?: $this->swishNumber);
        }

        $s = SwishSuite::getInstance()->getSettings()->swishNumber;
        return (string)(App::parseEnv($s) ?: $s);
    }
}
