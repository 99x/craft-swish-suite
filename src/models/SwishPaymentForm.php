<?php

namespace NinetyNineX\SwishSuite\models;

use craft\commerce\models\payments\BasePaymentForm;

class SwishPaymentForm extends BasePaymentForm
{
    public ?string $payerAlias = null;

    /** @return array<int, array<int|string, mixed>> */
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            [['payerAlias'], 'string'],
            [['payerAlias'], 'match',
                'pattern' => '/^46[0-9]{7,13}$/',
                'message' => 'Enter a valid Swedish phone number in E.164 format (46XXXXXXXXX).',
                'skipOnEmpty' => true,
            ],
        ]);
    }
}
