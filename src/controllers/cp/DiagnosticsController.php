<?php

namespace NinetyNineX\SwishSuite\controllers\cp;

use craft\web\Controller;
use NinetyNineX\SwishSuite\SwishSuite;
use yii\web\Response;

class DiagnosticsController extends Controller
{
    public function actionIndex(): Response
    {
        $this->requireCpRequest();

        $settings = SwishSuite::getInstance()->getSettings();
        $resolvedConfig = SwishSuite::getInstance()->swishPayment->getResolvedConfiguration();
        $callbackRoute = '/' . ltrim(SwishSuite::getInstance()->getCallbackRoute(), '/');
        $warnings = [];

        if ($resolvedConfig['merchantNumber'] === '') {
            $warnings[] = 'Merchant number is missing or unresolved.';
        }
        if ($resolvedConfig['certPath'] === '') {
            $warnings[] = 'Certificate path is missing.';
        } elseif (!is_file($resolvedConfig['certPath'])) {
            $warnings[] = 'Certificate path does not point to an existing file.';
        }
        if ($resolvedConfig['caPath'] === '') {
            $warnings[] = 'CA path is missing.';
        } elseif (!is_file($resolvedConfig['caPath'])) {
            $warnings[] = 'CA path does not point to an existing file.';
        }
        if ($callbackRoute === '/') {
            $warnings[] = 'Callback route could not be resolved.';
        }

        return $this->renderTemplate('swish-suite/cp/diagnostics/index', [
            'baseUrl' => $resolvedConfig['baseUrl'],
            'merchantNumber' => $this->maskValue($resolvedConfig['merchantNumber']),
            'environmentLabel' => $settings->testMode ? 'MSS Test' : 'Production',
            'callbackRoute' => $callbackRoute,
            'certPath' => $resolvedConfig['certPath'],
            'certPathExists' => $resolvedConfig['certPath'] !== '' && is_file($resolvedConfig['certPath']),
            'caPath' => $resolvedConfig['caPath'],
            'caPathExists' => $resolvedConfig['caPath'] !== '' && is_file($resolvedConfig['caPath']),
            'certPasswordConfigured' => $resolvedConfig['certPasswordConfigured'],
            'logsEnabled' => $settings->logsEnabled,
            'warnings' => $warnings,
        ]);
    }

    private function maskValue(string $value): string
    {
        if ($value === '') {
            return 'Not configured';
        }

        if (strlen($value) <= 4) {
            return str_repeat('*', strlen($value));
        }

        return substr($value, 0, 2) . str_repeat('*', strlen($value) - 4) . substr($value, -2);
    }
}
