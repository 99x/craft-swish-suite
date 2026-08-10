<?php

declare(strict_types=1);

namespace NinetyNineX\SwishSuite\Tests\Integration;

use craft\commerce\elements\Order;
use craft\commerce\models\Transaction;
use PHPUnit\Framework\TestCase;
use NinetyNineX\SwishSuite\gateways\SwishGateway;
use NinetyNineX\SwishSuite\models\SwishPaymentForm;
use NinetyNineX\SwishSuite\services\SwishPaymentService;
use NinetyNineX\SwishSuite\SwishSuite;

/**
 * Exercises SwishGateway::purchase() against a real, bootstrapped Craft
 * application (URL generation, sites, i18n, and a real ActiveRecord write to
 * the throwaway integration-test database) — only the outbound Swish API
 * call itself is stubbed, since real network access isn't appropriate here.
 */
class SwishGatewayIntegrationTest extends TestCase
{
    private SwishGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registerRealPluginWithStubbedApi();
        $this->gateway = new SwishGateway();
    }

    protected function tearDown(): void
    {
        SwishSuite::setInstance(null);
        parent::tearDown();
    }

    public function test_purchase_requires_valid_amount(): void
    {
        $transaction = $this->createTransaction('invalid-amount');
        $response = $this->gateway->purchase($transaction, new SwishPaymentForm());

        self::assertFalse($response->isSuccessful());
        self::assertFalse($response->isProcessing());
        self::assertFalse($response->isRedirect());
        self::assertStringContainsString('Invalid payment amount', $response->getMessage());
    }

    public function test_purchase_returns_redirect_response(): void
    {
        $form = new SwishPaymentForm();
        $form->payerAlias = null;

        $transaction = $this->createTransaction('100.00');
        $response = $this->gateway->purchase($transaction, $form);

        self::assertFalse($response->isSuccessful());
        self::assertTrue($response->isProcessing());
        self::assertTrue($response->isRedirect());
        self::assertNotEmpty($response->getRedirectUrl());
        self::assertStringContainsString('/swish/payments/waiting', $response->getRedirectUrl());
    }

    public function test_purchase_with_payer_alias_uses_ecommerce_flow(): void
    {
        $form = new SwishPaymentForm();
        $form->payerAlias = '46701234567';

        $transaction = $this->createTransaction('50.00');
        $response = $this->gateway->purchase($transaction, $form);

        self::assertTrue($response->isProcessing());
        self::assertTrue($response->isRedirect());
    }

    public function test_purchase_without_payer_alias_uses_mcommerce_flow(): void
    {
        $form = new SwishPaymentForm();
        $form->payerAlias = null;

        $transaction = $this->createTransaction('75.00');
        $response = $this->gateway->purchase($transaction, $form);

        self::assertTrue($response->isProcessing());
        self::assertTrue($response->isRedirect());
    }

    private function registerRealPluginWithStubbedApi(): void
    {
        $plugin = new SwishSuite('swish-suite', null, SwishSuite::config());

        $settings = $plugin->getSettings();
        $settings->swishNumber = '1231111111';
        $settings->certPath = '/tmp/fake-cert.p12';
        $settings->caPath = '/tmp/fake-ca.pem';
        $settings->testMode = true;

        // Only the outbound Swish API call is faked — everything else
        // (settings, logging, callback route) is the real component.
        $swishPayment = $this->getMockBuilder(SwishPaymentService::class)
            ->onlyMethods(['createPaymentRequest'])
            ->getMock();
        $swishPayment->method('createPaymentRequest')->willReturn([
            'paymentRequestToken' => 'test-payment-request-token',
        ]);
        $plugin->set('swishPayment', $swishPayment);

        SwishSuite::setInstance($plugin);
    }

    private function createTransaction(string $amount): Transaction
    {
        $order = new Order();
        $order->reference = 'TEST-ORDER-001';
        $order->returnUrl = '/shop/order';
        $order->cancelUrl = '/shop/cart';

        $transaction = new Transaction();
        $transaction->paymentAmount = (float)$amount;
        $transaction->hash = 'test-hash-' . uniqid();
        $transaction->setOrder($order);

        return $transaction;
    }
}
