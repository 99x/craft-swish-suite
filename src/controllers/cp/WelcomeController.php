<?php

namespace NinetyNineX\SwishSuite\controllers\cp;

use craft\helpers\App;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use GuzzleHttp\Client;
use NinetyNineX\SwishSuite\SwishSuite;
use yii\web\Response;

class WelcomeController extends Controller
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

        $callbackUrl = UrlHelper::siteUrl($callbackRoute);
        $callbackUrlHealthy = $this->isCallbackUrlHealthy($callbackUrl);

        if (!$callbackUrlHealthy && !$settings->testMode) {
            $warnings[] = "Callback URL ($callbackRoute) may not be reachable. Verify it is publicly accessible over HTTPS.";
        }

        return $this->renderTemplate('swish-suite/cp/welcome/index', [
            'warnings' => $warnings,
            'environmentLabel' => $settings->testMode ? 'MSS Test' : 'Production',
            'callbackRoute' => $callbackRoute,
            'callbackUrl' => $callbackUrl,
            'callbackUrlHealthy' => $callbackUrlHealthy,
            'merchantNumber' => $this->maskValue((string)(App::parseEnv($settings->swishNumber) ?: $settings->swishNumber)),
            'baseUrl' => $resolvedConfig['baseUrl'],
            'certPath' => $resolvedConfig['certPath'],
            'certPathExists' => $resolvedConfig['certPath'] !== '' && is_file($resolvedConfig['certPath']),
            'caPath' => $resolvedConfig['caPath'],
            'caPathExists' => $resolvedConfig['caPath'] !== '' && is_file($resolvedConfig['caPath']),
            'certPasswordConfigured' => $resolvedConfig['certPasswordConfigured'],
            'logsEnabled' => $settings->logsEnabled,
        ]);
    }

    private function isCallbackUrlHealthy(string $callbackUrl): bool
    {
        try {
            $client = new Client(['timeout' => 3]);
            $response = $client->head($callbackUrl, ['verify' => false, 'allow_redirects' => true]);
            return in_array($response->getStatusCode(), [200, 405, 400, 401], true);
        } catch (\Throwable) {
            return false;
        }
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
