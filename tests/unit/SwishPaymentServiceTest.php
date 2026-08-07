<?php

declare(strict_types=1);

namespace NinetyNineX\SwishSuite\Tests\Unit;

use NinetyNineX\SwishSuite\services\SwishPaymentService;
use PHPUnit\Framework\TestCase;

/**
 * Stub that bypasses Craft/Yii initialization so we can test pure methods.
 */
class TestableSwishPaymentService extends SwishPaymentService
{
    public function init(): void
    {
        // intentionally empty — skips Craft bootstrap
    }
}

class SwishPaymentServiceTest extends TestCase
{
    private TestableSwishPaymentService $service;

    protected function setUp(): void
    {
        $this->service = new TestableSwishPaymentService();
    }

    // -------------------------------------------------------------------------
    // normalizeAmountToMinorUnits
    // -------------------------------------------------------------------------

    public function testNormalizeAmountWhole(): void
    {
        self::assertSame(10000, $this->service->normalizeAmountToMinorUnits('100'));
    }

    public function testNormalizeAmountDecimal(): void
    {
        self::assertSame(10050, $this->service->normalizeAmountToMinorUnits('100.50'));
    }

    public function testNormalizeAmountOneDecimalPlace(): void
    {
        self::assertSame(990, $this->service->normalizeAmountToMinorUnits('9.9'));
    }

    public function testNormalizeAmountFromFloat(): void
    {
        self::assertSame(199, $this->service->normalizeAmountToMinorUnits(1.99));
    }

    public function testNormalizeAmountFromInt(): void
    {
        self::assertSame(500, $this->service->normalizeAmountToMinorUnits(5));
    }

    public function testNormalizeAmountNullReturnsNull(): void
    {
        self::assertNull($this->service->normalizeAmountToMinorUnits(null));
    }

    public function testNormalizeAmountEmptyStringReturnsNull(): void
    {
        self::assertNull($this->service->normalizeAmountToMinorUnits(''));
    }

    public function testNormalizeAmountNegativeReturnsNull(): void
    {
        self::assertNull($this->service->normalizeAmountToMinorUnits('-10'));
    }

    public function testNormalizeAmountScientificNotationReturnsNull(): void
    {
        self::assertNull($this->service->normalizeAmountToMinorUnits('1e6'));
    }

    public function testNormalizeAmountZeroReturnsNull(): void
    {
        self::assertNull($this->service->normalizeAmountToMinorUnits('0'));
    }

    public function testNormalizeAmountThreeDecimalsReturnsNull(): void
    {
        self::assertNull($this->service->normalizeAmountToMinorUnits('10.123'));
    }

    // -------------------------------------------------------------------------
    // formatAmountForApi
    // -------------------------------------------------------------------------

    public function testFormatAmountForApiRoundNumber(): void
    {
        self::assertSame('100.00', $this->service->formatAmountForApi(10000));
    }

    public function testFormatAmountForApiWithCents(): void
    {
        self::assertSame('9.99', $this->service->formatAmountForApi(999));
    }

    public function testFormatAmountForApiSingleCent(): void
    {
        self::assertSame('0.01', $this->service->formatAmountForApi(1));
    }

    // -------------------------------------------------------------------------
    // formatAmountForDisplay
    // -------------------------------------------------------------------------

    public function testFormatAmountForDisplayNormal(): void
    {
        self::assertSame('100.00 SEK', $this->service->formatAmountForDisplay(10000));
    }

    public function testFormatAmountForDisplayNullReturnsZero(): void
    {
        self::assertSame('0.00 SEK', $this->service->formatAmountForDisplay(null));
    }

    public function testFormatAmountForDisplayCustomCurrency(): void
    {
        self::assertSame('50.00 EUR', $this->service->formatAmountForDisplay(5000, 'EUR'));
    }

    // -------------------------------------------------------------------------
    // generateSafeReference  [C4 — simplified implementation]
    // -------------------------------------------------------------------------

    public function testGenerateSafeReferenceMatchesPattern(): void
    {
        $ref = $this->service->generateSafeReference('ORDER');
        self::assertMatchesRegularExpression('/^[A-Z0-9\-]{2,35}$/', $ref);
    }

    public function testGenerateSafeReferenceMaxLength(): void
    {
        $ref = $this->service->generateSafeReference('ORDER');
        self::assertLessThanOrEqual(35, strlen($ref));
    }

    public function testGenerateSafeReferenceIsUppercase(): void
    {
        $ref = $this->service->generateSafeReference('order');
        self::assertSame(strtoupper($ref), $ref);
    }

    public function testGenerateSafeReferenceIsDeterministicallyBounded(): void
    {
        // Generate multiple and ensure all are valid
        for ($i = 0; $i < 10; $i++) {
            $ref = $this->service->generateSafeReference('TEST');
            self::assertMatchesRegularExpression('/^[A-Z0-9\-]{2,35}$/', $ref);
        }
    }

    /** @test C4 — special characters are stripped from prefix */
    public function testGenerateSafeReferenceStripsSpecialChars(): void
    {
        $ref = $this->service->generateSafeReference('order#2024!');
        self::assertMatchesRegularExpression('/^[A-Z0-9\-]{2,35}$/', $ref);
        self::assertStringStartsWith('ORDER', $ref);
    }

    /** @test C4 — empty/invalid prefix falls back to 'ORDER' */
    public function testGenerateSafeReferenceEmptyPrefixFallsBackToOrder(): void
    {
        $ref = $this->service->generateSafeReference('!!!');
        self::assertStringStartsWith('ORDER-', $ref);
        self::assertMatchesRegularExpression('/^[A-Z0-9\-]{2,35}$/', $ref);
    }

    /** @test C4 — very long prefix is truncated to 35 chars total */
    public function testGenerateSafeReferenceLongPrefixTruncated(): void
    {
        $ref = $this->service->generateSafeReference('AVERYLONGPREFIXSTRING');
        self::assertLessThanOrEqual(35, strlen($ref));
        self::assertMatchesRegularExpression('/^[A-Z0-9\-]{2,35}$/', $ref);
    }

    // -------------------------------------------------------------------------
    // detectP12  [A1 — P12 certificate support, via reflection]
    // -------------------------------------------------------------------------

    /** @test A1 — .p12 extension is detected as P12 format */
    public function testDetectP12ReturnsTrueForP12Extension(): void
    {
        $result = $this->invokePrivate('detectP12', ['/path/to/cert.p12']);
        self::assertTrue($result);
    }

    /** @test A1 — .pfx extension is also detected as P12 format */
    public function testDetectP12ReturnsTrueForPfxExtension(): void
    {
        $result = $this->invokePrivate('detectP12', ['/path/to/cert.pfx']);
        self::assertTrue($result);
    }

    /** @test A1 — .pem extension is NOT detected as P12 */
    public function testDetectP12ReturnsFalseForPemExtension(): void
    {
        $result = $this->invokePrivate('detectP12', ['/path/to/cert.pem']);
        self::assertFalse($result);
    }

    /** @test A1 — extension matching is case-insensitive */
    public function testDetectP12IsCaseInsensitive(): void
    {
        self::assertTrue($this->invokePrivate('detectP12', ['/path/to/cert.P12']));
        self::assertTrue($this->invokePrivate('detectP12', ['/path/to/cert.PFX']));
        self::assertFalse($this->invokePrivate('detectP12', ['/path/to/cert.PEM']));
    }

    /** @test A1 — no extension defaults to non-P12 */
    public function testDetectP12ReturnsFalseForNoExtension(): void
    {
        $result = $this->invokePrivate('detectP12', ['/path/to/certfile']);
        self::assertFalse($result);
    }

    // -------------------------------------------------------------------------
    // clientCache  [C2 — Guzzle Client is cached per API version, via reflection]
    // -------------------------------------------------------------------------

    /** @test C2 — calling getClient twice returns the same instance */
    public function testGetClientReturnsSameInstance(): void
    {
        // Pre-seed the private cache with a dummy Client instance so we don't
        // need real certificate files during the test.
        // Properties are declared on the parent class, so we must reflect against it.
        $dummyClient = $this->createMock(\GuzzleHttp\Client::class);

        $parentRef = new \ReflectionClass(\NinetyNineX\SwishSuite\services\SwishPaymentService::class);
        $cacheProp = $parentRef->getProperty('clientCache');
        $cacheProp->setAccessible(true);
        $cacheProp->setValue($this->service, ['v2' => $dummyClient]);

        // Call getClient twice via reflection and assert the same object is returned.
        $first  = $this->invokePrivate('getClient', ['v2']);
        $second = $this->invokePrivate('getClient', ['v2']);

        self::assertSame($first, $second);
        self::assertSame($dummyClient, $first);
    }

    // -------------------------------------------------------------------------
    // generatePaymentId
    // -------------------------------------------------------------------------

    public function testGeneratePaymentIdIsUuidWithoutHyphens(): void
    {
        $id = $this->service->generatePaymentId();
        // 32 uppercase hex characters (UUID without hyphens)
        self::assertMatchesRegularExpression('/^[A-F0-9]{32}$/', $id);
    }

    public function testGeneratePaymentIdIsUnique(): void
    {
        self::assertNotSame(
            $this->service->generatePaymentId(),
            $this->service->generatePaymentId()
        );
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function invokePrivate(string $method, array $args = []): mixed
    {
        $ref = new \ReflectionClass($this->service);
        $m   = $ref->getMethod($method);
        $m->setAccessible(true);
        return $m->invokeArgs($this->service, $args);
    }
}


// -------------------------------------------------------------------------
// RefundWindowTest  [B3 — dateCreated normalization in RefundsController]
// -------------------------------------------------------------------------

/**
 * Tests the private parseDateTime helper without Craft bootstrapping.
 * We isolate it in a minimal stub that exposes the method.
 */
class ParseDateTimeStub
{
    public function parseDateTime(mixed $value): ?\DateTime
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

class RefundWindowTest extends TestCase
{
    private ParseDateTimeStub $stub;

    protected function setUp(): void
    {
        $this->stub = new ParseDateTimeStub();
    }

    /** @test B3 — DateTime object passes through unchanged */
    public function testParseDateTimeReturnsSameDateTimeObject(): void
    {
        $dt     = new \DateTime('2024-01-15 10:00:00');
        $result = $this->stub->parseDateTime($dt);
        self::assertEquals($dt, $result);
    }

    /** @test B3 — DateTimeImmutable is converted to mutable DateTime */
    public function testParseDateTimeConvertsImmutable(): void
    {
        $immutable = new \DateTimeImmutable('2024-01-15 10:00:00');
        $result    = $this->stub->parseDateTime($immutable);
        self::assertInstanceOf(\DateTime::class, $result);
        self::assertEquals($immutable->getTimestamp(), $result->getTimestamp());
    }

    /** @test B3 — ISO datetime string is parsed correctly */
    public function testParseDateTimeFromIsoString(): void
    {
        $result = $this->stub->parseDateTime('2024-01-15 10:00:00');
        self::assertInstanceOf(\DateTime::class, $result);
        self::assertSame('2024-01-15 10:00:00', $result->format('Y-m-d H:i:s'));
    }

    /** @test B3 — String older than 13 months is detected as out-of-window */
    public function testRefundWindowEnforcedForStringDate(): void
    {
        $oldDate = date('Y-m-d H:i:s', strtotime('-14 months'));
        $result  = $this->stub->parseDateTime($oldDate);
        $cutoff  = (new \DateTime())->modify('-13 months');

        self::assertInstanceOf(\DateTime::class, $result);
        self::assertLessThan($cutoff, $result, 'A 14-month-old date must be outside the 13-month refund window');
    }

    /** @test B3 — String within 13 months is within refund window */
    public function testDateWithinWindowIsNotBlocked(): void
    {
        $recentDate = date('Y-m-d H:i:s', strtotime('-6 months'));
        $result     = $this->stub->parseDateTime($recentDate);
        $cutoff     = (new \DateTime())->modify('-13 months');

        self::assertInstanceOf(\DateTime::class, $result);
        self::assertGreaterThan($cutoff, $result, 'A 6-month-old date must be inside the 13-month refund window');
    }

    /** @test B3 — null input returns null (graceful) */
    public function testParseDateTimeNullReturnsNull(): void
    {
        self::assertNull($this->stub->parseDateTime(null));
    }

    /** @test B3 — empty string returns null */
    public function testParseDateTimeEmptyStringReturnsNull(): void
    {
        self::assertNull($this->stub->parseDateTime(''));
    }

    /** @test B3 — invalid string returns null (no silent bypass) */
    public function testParseDateTimeInvalidStringReturnsNull(): void
    {
        self::assertNull($this->stub->parseDateTime('not-a-date'));
    }
}
