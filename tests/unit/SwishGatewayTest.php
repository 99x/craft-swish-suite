<?php

declare(strict_types=1);

namespace NinetyNineX\SwishSuite\Tests\Unit;

use craft\commerce\models\payments\BasePaymentForm;
use craft\commerce\models\Transaction;
use craft\commerce\records\Transaction as TransactionRecord;
use NinetyNineX\SwishSuite\gateways\SwishGateway;
use NinetyNineX\SwishSuite\gateways\SwishRequestResponse;
use NinetyNineX\SwishSuite\models\SwishPaymentForm;
use PHPUnit\Framework\TestCase;

/**
 * purchase()'s success paths (which need a real Craft app for URL generation,
 * sites, and ActiveRecord persistence) are covered separately in
 * tests/integration/SwishGatewayIntegrationTest — this file only covers
 * behavior that doesn't require SwishSuite::getInstance() to resolve.
 */
class SwishGatewayTest extends TestCase
{
    private SwishGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gateway = new SwishGateway();
    }

    public function test_gateway_does_not_support_authorize(): void
    {
        $form = new SwishPaymentForm();
        $transaction = $this->createMockTransaction('100.00');

        $this->expectException(\yii\base\NotSupportedException::class);
        $this->gateway->authorize($transaction, $form);
    }

    public function test_gateway_does_not_support_capture(): void
    {
        $this->expectException(\yii\base\NotSupportedException::class);
        $this->gateway->capture($this->createMockTransaction('100.00'), 'ref123');
    }

    public function test_gateway_does_not_support_payment_sources(): void
    {
        $form = new SwishPaymentForm();
        $this->expectException(\yii\base\NotSupportedException::class);
        $this->gateway->createPaymentSource($form, 1);
    }

    public function test_complete_purchase_is_not_supported(): void
    {
        $transaction = $this->createMockTransaction('100.00');
        $response = $this->gateway->completePurchase($transaction);

        self::assertFalse($response->isSuccessful());
        self::assertFalse($response->isProcessing());
        self::assertFalse($response->isRedirect());
        self::assertStringContainsString('does not use synchronous payment completion', $response->getMessage());
    }

    public function test_payment_form_validates_swedish_phone_number(): void
    {
        $form = new SwishPaymentForm();

        $form->payerAlias = '46701234567';
        self::assertTrue($form->validate(['payerAlias']));

        $form->payerAlias = 'invalid-number';
        self::assertFalse($form->validate(['payerAlias']));

        $form->payerAlias = '';
        self::assertTrue($form->validate(['payerAlias']));
    }

    public function test_billing_address_condition_accepts_null(): void
    {
        $this->gateway->setBillingAddressCondition(null);
        self::assertTrue(true);
    }

    public function test_shipping_address_condition_accepts_null(): void
    {
        $this->gateway->setShippingAddressCondition(null);
        self::assertTrue(true);
    }

    private function createMockTransaction(string $amount): Transaction
    {
        $transaction = $this->createMock(Transaction::class);
        $transaction->paymentAmount = (float)$amount;
        $transaction->hash = 'test-hash-' . uniqid();

        $order = $this->createMock(\craft\commerce\elements\Order::class);
        $order->reference = 'TEST-ORDER-001';
        $order->returnUrl = '/shop/order';
        $order->cancelUrl = '/shop/cart';

        $transaction->method('getOrder')->willReturn($order);

        return $transaction;
    }
}
