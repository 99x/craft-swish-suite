<?php

namespace NinetyNineX\SwishSuite\models;

use Craft;
use craft\base\Model;
use craft\helpers\App;

class Settings extends Model
{
    private const DEFAULT_CHECKOUT_TITLE = 'Pay with Swish';
    private const DEFAULT_REDIRECT_URL = '/';

    public string $swishNumber   = '';
    public string $certPath      = '';
    public string $certPassword  = '';
    public string $caPath        = '';
    public bool   $testMode      = true;
    public bool   $logsEnabled   = true;
    public string $checkoutTitle = self::DEFAULT_CHECKOUT_TITLE;
    public string $successUrl    = self::DEFAULT_REDIRECT_URL;
    public string $cancelUrl     = self::DEFAULT_REDIRECT_URL;

    /** @return array<int, array<int|string, mixed>> */
    public function rules(): array
    {
        return [
            [['swishNumber', 'certPath', 'caPath'], 'required'],
            [['swishNumber', 'certPath', 'certPassword', 'caPath', 'successUrl', 'cancelUrl', 'checkoutTitle'], 'string'],
            [['testMode', 'logsEnabled'], 'boolean'],
            [['certPath', 'caPath'], function (string $attribute): void {
                $raw = trim((string)$this->$attribute);
                if ($raw === '') {
                    return; // 'required' rule handles empty values
                }

                $parsed = App::parseEnv($raw);

                // Skip file check when the env var is not defined at config time
                if (!is_string($parsed) || str_starts_with($parsed, '$')) {
                    return;
                }

                try {
                    $resolved = (string)Craft::getAlias($parsed);
                } catch (\yii\base\InvalidArgumentException) {
                    $resolved = $parsed;
                }

                if (!file_exists($resolved) || !is_readable($resolved)) {
                    $this->addError($attribute, Craft::t('swish-suite', 'File not found or not readable: {path}', ['path' => $resolved]));
                }
            }],
        ];
    }

    public function beforeValidate(): bool
    {
        $this->testMode = filter_var($this->testMode, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? true;
        $this->logsEnabled = filter_var($this->logsEnabled, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? true;
        $this->swishNumber = trim($this->swishNumber);
        $this->certPath = trim($this->certPath);
        $this->certPassword = trim($this->certPassword);
        $this->caPath = trim($this->caPath);
        $this->checkoutTitle = trim($this->checkoutTitle) !== '' ? trim($this->checkoutTitle) : self::DEFAULT_CHECKOUT_TITLE;
        $this->successUrl = trim($this->successUrl) !== '' ? trim($this->successUrl) : self::DEFAULT_REDIRECT_URL;
        $this->cancelUrl = trim($this->cancelUrl) !== '' ? trim($this->cancelUrl) : self::DEFAULT_REDIRECT_URL;

        return parent::beforeValidate();
    }
}
