<?php

declare(strict_types=1);

namespace NinetyNineX\SwishSuite\Tests\Unit;

use NinetyNineX\SwishSuite\gateways\SwishRequestResponse;
use PHPUnit\Framework\TestCase;

class SwishRequestResponseTest extends TestCase
{
    public function test_asRedirect_returns_correct_state(): void
    {
        $r = SwishRequestResponse::asRedirect('https://example.com/waiting', 'PAYMENTID123');

        self::assertFalse($r->isSuccessful());
        self::assertTrue($r->isProcessing());
        self::assertTrue($r->isRedirect());
        self::assertSame('https://example.com/waiting', $r->getRedirectUrl());
        self::assertSame('PAYMENTID123', $r->getTransactionReference());
        self::assertSame('REDIRECT', $r->getCode());
    }

    public function test_asSuccess_returns_correct_state(): void
    {
        $r = SwishRequestResponse::asSuccess('SWISHREF123', 'Paid');

        self::assertTrue($r->isSuccessful());
        self::assertFalse($r->isProcessing());
        self::assertFalse($r->isRedirect());
        self::assertSame('SWISHREF123', $r->getTransactionReference());
        self::assertSame('Paid', $r->getMessage());
        self::assertSame('SUCCESS', $r->getCode());
        self::assertSame('', $r->getRedirectUrl());
    }

    public function test_asFailure_returns_correct_state(): void
    {
        $r = SwishRequestResponse::asFailure('Something went wrong', 'MY_ERROR');

        self::assertFalse($r->isSuccessful());
        self::assertFalse($r->isProcessing());
        self::assertFalse($r->isRedirect());
        self::assertSame('MY_ERROR', $r->getCode());
        self::assertSame('Something went wrong', $r->getMessage());
        self::assertSame('', $r->getRedirectUrl());
        self::assertSame('', $r->getTransactionReference());
    }

    public function test_asFailure_default_code_is_ERROR(): void
    {
        $r = SwishRequestResponse::asFailure('oops');
        self::assertSame('ERROR', $r->getCode());
    }

    public function test_getData_returns_null_by_default(): void
    {
        $r = SwishRequestResponse::asSuccess('REF');
        self::assertNull($r->getData());
    }

    public function test_getRequest_always_returns_null(): void
    {
        self::assertNull(SwishRequestResponse::asRedirect('/url', 'ID')->getRequest());
        self::assertNull(SwishRequestResponse::asSuccess('REF')->getRequest());
        self::assertNull(SwishRequestResponse::asFailure('err')->getRequest());
    }
}
