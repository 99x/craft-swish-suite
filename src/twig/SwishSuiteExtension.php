<?php

namespace NinetyNineX\SwishSuite\twig;

use NinetyNineX\SwishSuite\services\SwishPaymentService;
use NinetyNineX\SwishSuite\SwishSuite;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;
use Twig\TwigFilter;

class SwishSuiteExtension extends AbstractExtension implements GlobalsInterface
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('swishSek', [$this, 'formatSek']),
        ];
    }

    public function getGlobals(): array
    {
        return [
            'swishSuite' => SwishSuite::getInstance(),
        ];
    }

    public function formatSek(?int $minorUnits, string $currency = SwishPaymentService::CURRENCY_SEK): string
    {
        return SwishSuite::getInstance()->swishPayment->formatAmountForDisplay($minorUnits, $currency);
    }
}
