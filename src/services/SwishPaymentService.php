<?php

namespace NinetyNineX\SwishSuite\services;

use craft\helpers\App;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\SvgWriter;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\HandlerStack;
use NinetyNineX\SwishSuite\SwishSuite;
use Ramsey\Uuid\Uuid;
use yii\base\Component;

class SwishPaymentService extends Component
{
    public const CURRENCY_SEK = 'SEK';
    public const FLOW_ECOMMERCE = 'ecommerce';
    public const FLOW_MCOMMERCE = 'mcommerce';

    public const EVENT_BEFORE_PAYMENT_CREATED = 'beforePaymentCreated';
    public const EVENT_AFTER_PAYMENT_CREATED  = 'afterPaymentCreated';
    public const EVENT_BEFORE_REFUND_CREATED  = 'beforeRefundCreated';
    public const EVENT_AFTER_REFUND_CREATED   = 'afterRefundCreated';

    private const API_VERSION_V1 = 'v1';
    private const API_VERSION_V2 = 'v2';
    private const DEFAULT_BASE_URL_TEST_V2 = 'https://mss.cpc.getswish.net/swish-cpcapi/api/v2';
    private const DEFAULT_BASE_URL_PROD_V2 = 'https://cpc.getswish.net/swish-cpcapi/api/v2';
    private const HTTPS_PREFIX = 'https://';
    private const CONTENT_TYPE_HEADER = 'Content-Type';
    private const CONTENT_TYPE_JSON = 'application/json';
    private const CONTENT_TYPE_JSON_PATCH = 'application/json-patch+json';
    private const ENDPOINT_PAYMENT_REQUESTS = 'paymentrequests';
    private const ENDPOINT_REFUNDS = 'refunds';
    private const REFERENCE_PREFIX_ORDER = 'ORDER';

    private string $baseUrlV1 = '';
    private string $baseUrlV2 = '';
    private string $swishNumber = '';
    private string $certPath = '';
    private string $certPassword = '';
    private string $caPath = '';
    private bool $certIsP12 = false;

    /** @var array<string, Client> */
    private array $clientCache = [];

    public function init(): void
    {
        parent::init();

        $settings = SwishSuite::getInstance()->getSettings();
        $baseUrl = App::parseEnv('$SWISH_BASE_URL');

        $resolvedBaseUrlV2 = ($baseUrl !== null && $baseUrl !== '$SWISH_BASE_URL')
            ? (string)$baseUrl
            : ($settings->testMode
                ? self::DEFAULT_BASE_URL_TEST_V2
                : self::DEFAULT_BASE_URL_PROD_V2);

        $this->baseUrlV2    = $this->normalizeBaseUrl($resolvedBaseUrlV2);
        $this->baseUrlV1    = $this->buildVersionedBaseUrl($this->baseUrlV2, self::API_VERSION_V1);
        $this->swishNumber  = (string)(App::parseEnv($settings->swishNumber) ?: $settings->swishNumber);
        $this->certPath     = (string)(App::parseEnv($settings->certPath) ?: $settings->certPath);
        $this->certPassword = (string)(App::parseEnv($settings->certPassword) ?: $settings->certPassword);
        $this->caPath       = (string)(App::parseEnv($settings->caPath) ?: $settings->caPath);
        $this->certIsP12    = $this->detectP12($this->certPath);

        SwishSuite::getInstance()->helpers->logInfo(
            sprintf('[INIT] certFormat:%s baseUrlV2:%s', $this->certIsP12 ? 'p12' : 'pem', $this->baseUrlV2),
            __METHOD__
        );
    }

    /**
     * Returns true when the certificate file extension signals a PKCS#12 bundle.
     * Guzzle passes `cert` to CURLOPT_SSLCERT without setting CURLOPT_SSLCERTTYPE,
     * so P12 files need a custom curl handler that sets the type flag explicitly.
     */
    private function detectP12(string $path): bool
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return in_array($ext, ['p12', 'pfx'], true);
    }

    private function getClient(string $apiVersion): Client
    {
        if (isset($this->clientCache[$apiVersion])) {
            return $this->clientCache[$apiVersion];
        }

        $baseOptions = [
            'base_uri'        => $this->getBaseUrl($apiVersion),
            'verify'          => $this->caPath,
            'connect_timeout' => 5,
            'timeout'         => 15,
            'headers'         => [self::CONTENT_TYPE_HEADER => self::CONTENT_TYPE_JSON],
        ];

        if ($this->certIsP12) {
            // Guzzle does not expose CURLOPT_SSLCERTTYPE. We inject it via a
            // custom CurlHandler that sets the option after Guzzle configures curl.
            $curlHandler = new CurlHandler();
            $stack = HandlerStack::create($curlHandler);
            $stack->push(function (callable $handler) {
                return function (\Psr\Http\Message\RequestInterface $request, array $options) use ($handler) {
                    $options['curl'][CURLOPT_SSLCERT]      = $options['cert'][0] ?? $this->certPath;
                    $options['curl'][CURLOPT_SSLCERTPASSWD] = $options['cert'][1] ?? $this->certPassword;
                    $options['curl'][CURLOPT_SSLCERTTYPE]  = 'P12';
                    unset($options['cert']);
                    return $handler($request, $options);
                };
            }, 'p12_cert_type');
            $baseOptions['handler'] = $stack;
            $baseOptions['cert']    = [$this->certPath, $this->certPassword];
        } else {
            $baseOptions['cert'] = [$this->certPath, $this->certPassword];
        }

        $this->clientCache[$apiVersion] = new Client($baseOptions);

        return $this->clientCache[$apiVersion];
    }

    public function generatePaymentId(): string
    {
        return strtoupper(str_replace('-', '', Uuid::uuid4()->toString()));
    }

    public function normalizeAmountToMinorUnits(int|float|string|null $amount): ?int
    {
        if ($amount === null || $amount === '') {
            return null;
        }

        // Strict decimal validation — rejects scientific notation (e.g. "1e6") and negatives
        if (!preg_match('/^\d+(\.\d{1,2})?$/', (string)$amount)) {
            return null;
        }

        $minorUnits = (int)round(((float)$amount) * 100);

        return $minorUnits > 0 ? $minorUnits : null;
    }

    public function formatAmountForApi(int $minorUnits): string
    {
        return number_format($minorUnits / 100, 2, '.', '');
    }

    public function generateQrCodeDataUri(string $data): string
    {
        $result = Builder::create()
            ->writer(new SvgWriter())
            ->data($data)
            ->size(280)
            ->margin(10)
            ->build();

        return $result->getDataUri();
    }

    public function formatAmountForDisplay(?int $minorUnits, string $currency = self::CURRENCY_SEK): string
    {
        if ($minorUnits === null) {
            return '0.00 ' . $currency;
        }

        return number_format($minorUnits / 100, 2, '.', ' ') . ' ' . $currency;
    }

    /** @return array<string, mixed> */
    public function getResolvedConfiguration(): array
    {
        return [
            'baseUrl'                => $this->baseUrlV2,
            'baseUrlV1'              => $this->baseUrlV1,
            'baseUrlV2'              => $this->baseUrlV2,
            'merchantNumber'         => $this->swishNumber,
            'certPath'               => $this->certPath,
            'certFormat'             => $this->certIsP12 ? 'p12' : 'pem',
            'certPasswordConfigured' => $this->certPassword !== '',
            'caPath'                 => $this->caPath,
        ];
    }

    /**
     * Generates a merchant payment reference that satisfies the Swish API constraint:
     * 2–35 characters, only [A-Z0-9-].
     */
    public function generateSafeReference(string $prefix = 'ORDER'): string
    {
        $safePrefix = strtoupper((string)preg_replace('/[^A-Z0-9]/i', '', $prefix)) ?: 'ORDER';
        $suffix     = strtoupper(substr(str_replace('-', '', Uuid::uuid4()->toString()), 0, 20));

        return substr($safePrefix . '-' . $suffix, 0, 35);
    }

    public function createPaymentRequest(
        string  $paymentId,
        int     $amountMinorUnits,
        string  $callbackUrl,
        ?string $payerAlias = null,
        ?string $reference = null,
        ?string $message = null,
        ?string $callbackIdentifier = null
    ): ?array {
        if (!str_starts_with($callbackUrl, self::HTTPS_PREFIX)) {
            throw new \RuntimeException(
                'Callback URL must use HTTPS. Current: ' . $callbackUrl
            );
        }

        $reference = $this->sanitizeReference($reference);
        $message   = $message !== null ? mb_substr(trim($message), 0, 50) : null;

        $payload = array_filter([
            'payeeAlias'            => $this->swishNumber,
            'amount'                => $this->formatAmountForApi($amountMinorUnits),
            'currency'              => self::CURRENCY_SEK,
            'callbackUrl'           => $callbackUrl,
            'payerAlias'            => $payerAlias,
            'payeePaymentReference' => $reference,
            'message'               => $message !== '' ? $message : null,
            'callbackIdentifier'    => $callbackIdentifier,
        ]);

        $response = $this->requestApi(
            'PUT',
            $this->getPaymentRequestsEndpoint($paymentId),
            $payload,
            [],
            self::API_VERSION_V2
        );

        if ($response === null) {
            return null;
        }

        return [
            'paymentId'           => $paymentId,
            'location'            => is_string($response['headers']['Location'][0] ?? null) ? $response['headers']['Location'][0] : null,
            'paymentRequestToken' => is_string($response['headers']['PaymentRequestToken'][0] ?? null) ? $response['headers']['PaymentRequestToken'][0] : null,
        ];
    }

    /** @return array<string, mixed>|null */
    public function getPaymentStatus(string $paymentId): ?array
    {
        $response = $this->requestApi('GET', $this->getPaymentRequestsEndpoint($paymentId), [], [], self::API_VERSION_V1);

        return $response !== null ? $response['body'] : null;
    }

    /** @return array<string, mixed>|null */
    public function getRefundStatus(string $refundId): ?array
    {
        $response = $this->requestApi('GET', $this->getRefundsEndpoint($refundId), [], [], self::API_VERSION_V1);

        return $response !== null ? $response['body'] : null;
    }

    public function cancelPayment(string $paymentId): bool
    {
        return $this->requestApi(
            'PATCH',
            $this->getPaymentRequestsEndpoint($paymentId),
            [['op' => 'replace', 'path' => '/status', 'value' => 'cancelled']],
            [self::CONTENT_TYPE_HEADER => self::CONTENT_TYPE_JSON_PATCH],
            self::API_VERSION_V1
        ) !== null;
    }

    public function createRefund(
        string  $refundId,
        string  $originalPaymentReference,
        int     $amountMinorUnits,
        string  $callbackUrl,
        ?string $message = null,
        ?string $callbackIdentifier = null
    ): ?array {
        if (!str_starts_with($callbackUrl, self::HTTPS_PREFIX)) {
            throw new \RuntimeException(
                'Callback URL must use HTTPS. Current: ' . $callbackUrl
            );
        }

        $message = $message !== null ? mb_substr(trim($message), 0, 50) : null;

        $payload = array_filter([
            'payerAlias'               => $this->swishNumber,
            'originalPaymentReference' => $originalPaymentReference,
            'amount'                   => $this->formatAmountForApi($amountMinorUnits),
            'currency'                 => self::CURRENCY_SEK,
            'callbackUrl'              => $callbackUrl,
            'message'                  => $message !== '' ? $message : null,
            'callbackIdentifier'       => $callbackIdentifier,
        ]);

        $response = $this->requestApi(
            'PUT',
            $this->getRefundsEndpoint($refundId),
            $payload,
            [],
            self::API_VERSION_V2
        );

        if ($response === null) {
            return null;
        }

        return [
            'refundId' => $refundId,
            'location' => is_string($response['headers']['Location'][0] ?? null) ? $response['headers']['Location'][0] : null,
        ];
    }

    private function sanitizeReference(?string $reference): string
    {
        if (!is_string($reference) || !preg_match('/^[A-Z0-9-]{2,35}$/', strtoupper($reference))) {
            return $this->generateSafeReference(self::REFERENCE_PREFIX_ORDER);
        }

        return strtoupper($reference);
    }

    private function normalizeBaseUrl(string $baseUrl): string
    {
        return rtrim($baseUrl, '/') . '/';
    }

    private function buildVersionedBaseUrl(string $baseUrl, string $targetVersion): string
    {
        $normalizedBaseUrl = $this->normalizeBaseUrl($baseUrl);

        return preg_replace('#/v[0-9]+/$#', '/' . $targetVersion . '/', $normalizedBaseUrl) ?? $normalizedBaseUrl;
    }

    private function getBaseUrl(string $apiVersion): string
    {
        return match ($apiVersion) {
            self::API_VERSION_V1 => $this->baseUrlV1,
            default              => $this->baseUrlV2,
        };
    }

    private function getPaymentRequestsEndpoint(string $paymentId): string
    {
        return self::ENDPOINT_PAYMENT_REQUESTS . '/' . urlencode($paymentId);
    }

    private function getRefundsEndpoint(string $refundId): string
    {
        return self::ENDPOINT_REFUNDS . '/' . urlencode($refundId);
    }

    /**
     * Makes an API request and returns a structured response array with separate
     * `statusCode`, `headers`, and `body` keys — never merging body into the root.
     *
     * @param array<string, mixed>|array<int, array<string, string>> $body
     * @param array<string, string> $additionalHeaders
     * @return array{statusCode: int, headers: array<string, array<int, string>>, body: array<string, mixed>}|null
     */
    private function requestApi(
        string $method,
        string $endpoint,
        array $body = [],
        array $additionalHeaders = [],
        string $apiVersion = self::API_VERSION_V2
    ): ?array {
        $headers = array_merge([
            self::CONTENT_TYPE_HEADER => self::CONTENT_TYPE_JSON,
        ], $additionalHeaders);

        // Redact PII from body before logging
        $safeBody = $body;
        if (is_array($safeBody) && isset($safeBody['payerAlias']) && is_string($safeBody['payerAlias'])) {
            $safeBody['payerAlias'] = '46***' . substr($safeBody['payerAlias'], -3);
        }
        $bodyPreview = json_encode($safeBody);
        $bodyPreview = is_string($bodyPreview) ? substr($bodyPreview, 0, 500) : '';

        SwishSuite::getInstance()->helpers->logInfo(
            '[API REQUEST] ' . json_encode([
                'method'      => strtoupper($method),
                'endpoint'    => $endpoint,
                'apiVersion'  => $apiVersion,
                'baseUrl'     => $this->getBaseUrl($apiVersion),
                'bodyPreview' => $bodyPreview,
            ]),
            __METHOD__
        );

        try {
            $options = ['headers' => $headers];
            if (strtoupper($method) !== 'GET') {
                $options['json'] = empty($body) ? new \stdClass() : $body;
            }

            $response     = $this->getClient($apiVersion)->request($method, $endpoint, $options);
            $responseBody = $response->getBody()->getContents();
            $decoded      = json_decode($responseBody, true);

            return [
                'statusCode' => $response->getStatusCode(),
                'headers'    => $response->getHeaders(),
                'body'       => is_array($decoded) ? $decoded : [],
            ];
        } catch (RequestException $e) {
            SwishSuite::getInstance()->helpers->logError(
                '[API ERROR] ' . $e->getMessage() . ' [' . $method . ' ' . $endpoint . ']',
                __METHOD__
            );
            throw $e;
        }
    }
}
