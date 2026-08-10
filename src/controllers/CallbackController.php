<?php

namespace NinetyNineX\SwishSuite\controllers;

use Craft;
use craft\web\Controller;
use NinetyNineX\SwishSuite\records\Payment;
use NinetyNineX\SwishSuite\records\Refund;
use NinetyNineX\SwishSuite\SwishSuite;
use yii\web\Response;

class CallbackController extends Controller
{
    protected array|int|bool $allowAnonymous = true;

    public function init(): void
    {
        parent::init();
        $this->enableCsrfValidation = false;
    }

    public function actionProcess(): Response
    {
        $request = Craft::$app->getRequest();
        $helpers = SwishSuite::getInstance()->helpers;
        $callbackHandler = SwishSuite::getInstance()->callbackHandler;

        if (!$request->getIsPost()) {
            return $this->asJson([
                'error' => 'Method not allowed',
                'message' => 'Este endpoint aceita apenas requisições POST do Swish API',
                'method' => $request->getMethod(),
            ])->setStatusCode(405);
        }

        $rawBody = $request->getRawBody();
        $headers = $request->getHeaders();

        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) {
            $helpers->logWarning(
                '[CALLBACK REQUEST] Invalid JSON payload — bodySize:' . strlen($rawBody),
                __METHOD__
            );
            return $this->asJson(['error' => 'Invalid JSON payload'])->setStatusCode(400);
        }

        // Redact PII before logging
        $safePayload = $payload;
        if (isset($safePayload['payerAlias']) && is_string($safePayload['payerAlias'])) {
            $safePayload['payerAlias'] = '46***' . substr($safePayload['payerAlias'], -3);
        }
        $helpers->logInfo(
            '[CALLBACK REQUEST] ' . (string)json_encode([
                'method' => $request->getMethod(),
                'url' => $request->getFullUri(),
                'headers' => ['callbackidentifier' => $headers->get('callbackidentifier')],
                'bodySize' => strlen($rawBody),
                'body' => $safePayload,
            ]),
            __METHOD__
        );

        $payload = $this->normalizePayload($payload);
        $entityId = is_string($payload['id'] ?? null) ? $payload['id'] : null;
        if ($entityId === null) {
            $helpers->logWarning('Callback payload missing id', __METHOD__);
            return $this->asJson(['error' => 'Callback payload missing id'])->setStatusCode(400);
        }

        $identifier = $headers->get('callbackidentifier');
        if (!$callbackHandler->validateCallback($identifier, $entityId)) {
            $helpers->logWarning("Invalid callbackIdentifier for entity {$entityId}", __METHOD__);
            return $this->asJson(['error' => 'Invalid callbackidentifier'])->setStatusCode(401);
        }

        try {
            // Wrap in a transaction to prevent race conditions when Swish retries
            // deliver two callbacks simultaneously for the same entity.
            // The FOR UPDATE lock inside processPaymentCallback / processRefundCallback
            // ensures only one writer proceeds at a time.
            $result = Craft::$app->db->transaction(function () use ($entityId, $payload, $helpers, $callbackHandler): string {
                $payment = Payment::findByPaymentId($entityId);
                if ($payment) {
                    if ($payment->isInTerminalState()) {
                        $helpers->logInfo(
                            "Skipping duplicate callback for payment {$entityId} already in terminal status {$payment->status}",
                            __METHOD__
                        );
                        return 'received';
                    }
                    $callbackHandler->processPaymentCallback($payload);
                    return 'received';
                }

                $refund = Refund::findByRefundId($entityId);
                if ($refund) {
                    if ($refund->isInTerminalState()) {
                        $helpers->logInfo(
                            "Skipping duplicate callback for refund {$entityId} already in terminal status {$refund->status}",
                            __METHOD__
                        );
                        return 'received';
                    }
                    $callbackHandler->processRefundCallback($payload);
                    return 'received';
                }

                $helpers->logWarning("Callback received for unknown entity id {$entityId}", __METHOD__);
                return 'unknown';
            });

            if ($result === 'unknown') {
                return $this->asJson(['error' => 'Unknown entity id'])->setStatusCode(404);
            }

            return $this->asJson(['status' => 'received']);
        } catch (\Exception $exception) {
            $helpers->logError('Error processing callback: ' . $exception->getMessage(), __METHOD__);
            return $this->asJson(['error' => 'Internal error processing callback'])->setStatusCode(500);
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function normalizePayload(array $payload): array
    {
        if (!isset($payload['eventType']) && is_string($payload['status'] ?? null)) {
            $payload['eventType'] = 'swish.payment.' . strtolower($payload['status']) . '.v1';
        }

        return $payload;
    }
}
