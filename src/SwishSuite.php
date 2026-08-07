<?php

namespace NinetyNineX\SwishSuite;

use Craft;
use craft\base\Model;
use craft\base\Plugin;
use craft\console\Application as ConsoleApplication;
use craft\events\RegisterTemplateRootsEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\helpers\App;
use craft\helpers\UrlHelper;
use craft\web\UrlManager;
use craft\web\View;
use NinetyNineX\SwishSuite\models\Settings;
use NinetyNineX\SwishSuite\services\CallbackHandlerService;
use NinetyNineX\SwishSuite\services\ErrorHandlerService;
use NinetyNineX\SwishSuite\services\HelperFunctionService;
use NinetyNineX\SwishSuite\services\SwishPaymentService;
use NinetyNineX\SwishSuite\twig\SwishSuiteExtension;
use yii\base\Event;

/**
 * @method static SwishSuite getInstance()
 * @method Settings getSettings()
 * @property SwishPaymentService $swishPayment
 * @property CallbackHandlerService $callbackHandler
 * @property HelperFunctionService $helpers
 * @property ErrorHandlerService $errorHandler
 */
class SwishSuite extends Plugin
{
    public const TEMPLATE_SETTINGS            = 'swish-suite/_settings';
    public const TEMPLATE_CHECKOUT_INDEX      = 'swish-suite/checkout/index';
    public const TEMPLATE_CHECKOUT_WAITING    = 'swish-suite/checkout/waiting';
    public const TEMPLATE_CP_WELCOME          = 'swish-suite/cp/welcome/index';
    public const TEMPLATE_CP_DASHBOARD        = 'swish-suite/cp/dashboard/index';
    public const TEMPLATE_CP_PAYMENTS_INDEX   = 'swish-suite/cp/payments/index';
    public const TEMPLATE_CP_PAYMENTS_VIEW    = 'swish-suite/cp/payments/_payment';
    public const TEMPLATE_CP_PAYMENTS_CREATE     = 'swish-suite/cp/payments/_create';
    public const TEMPLATE_CP_PAYMENTS_QR_WAITING = 'swish-suite/cp/payments/_qr-waiting';
    public const TEMPLATE_CP_REFUNDS_INDEX    = 'swish-suite/cp/refunds/index';
    public const TEMPLATE_CP_REFUNDS_VIEW     = 'swish-suite/cp/refunds/_refund';
    public const TEMPLATE_CP_REFUNDS_CREATE   = 'swish-suite/cp/refunds/_create';

    public const CP_ROUTE_BASE            = 'swish-suite';
    public const CP_ROUTE_WELCOME         = 'swish-suite/welcome';
    public const CP_ROUTE_DASHBOARD       = 'swish-suite/dashboard';
    public const CP_ROUTE_PAYMENTS        = 'swish-suite/payments';
    public const CP_ROUTE_PAYMENTS_CREATE = 'swish-suite/payments/new';
    public const CP_ROUTE_PAYMENTS_STORE  = 'swish-suite/payments/store';
    public const CP_ROUTE_REFUNDS         = 'swish-suite/refunds';
    public const CP_ROUTE_REFUNDS_CREATE  = 'swish-suite/refunds/new';
    public const CP_ROUTE_REFUNDS_STORE   = 'swish-suite/refunds/store';

    public const SWISH_APP_URL              = 'swish://paymentrequest';

    public const SITE_ROUTE_CHECKOUT        = 'swish/checkout';
    public const SITE_ROUTE_PAY             = 'swish/pay';
    public const SITE_ROUTE_PAYMENT_PROCESS = 'swish/payments/process';
    public const SITE_ROUTE_PAYMENT_WAITING = 'swish/payments/waiting';
    public const SITE_ROUTE_PAYMENT_POLL    = 'swish/payments/poll';
    public const SITE_ROUTE_PAYMENT_SUCCESS = 'swish/payments/success';
    public const SITE_ROUTE_PAYMENT_CANCEL  = 'swish/payments/cancel';
    public const DEFAULT_CALLBACK_ROUTE     = '/swish/callback';

    public bool $hasCpSection  = true;
    public bool $hasCpSettings = true;
    public string $schemaVersion = '1.2.0';

    /** @return array<string, mixed> */
    public static function config(): array
    {
        return [
            'components' => [
                'swishPayment'    => SwishPaymentService::class,
                'callbackHandler' => CallbackHandlerService::class,
                'helpers'         => HelperFunctionService::class,
                'errorHandler'    => ErrorHandlerService::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        // Console controllers must be registered before onInit
        if (Craft::$app instanceof ConsoleApplication) {
            $this->controllerNamespace = 'NinetyNineX\\SwishSuite\\console\\controllers';
        }

        Craft::$app->onInit(function (): void {
            $this->registerTemplateRoots();
            $this->registerTwigExtension();
            $this->registerCpRoutes();
            $this->registerSiteRoutes();
        });

        // Commerce integration is fully optional — only loaded when Commerce is installed.
        if (class_exists(\craft\commerce\Plugin::class)) {
            $this->registerCommerceIntegration();
        }
    }

    protected function createSettingsModel(): ?Model
    {
        return Craft::createObject(Settings::class);
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->view->renderTemplate(self::TEMPLATE_SETTINGS, [
            'plugin'   => $this,
            'settings' => $this->getSettings(),
        ]);
    }

    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();
        if ($item === null) {
            return null;
        }

        $item['label'] = Craft::t('swish-suite', 'Swish Suite');
        $item['url']   = self::CP_ROUTE_BASE;
        $item['subnav'] = [
            'dashboard' => [
                'label' => Craft::t('swish-suite', 'Dashboard'),
                'url'   => self::CP_ROUTE_DASHBOARD,
            ],
            'payments' => [
                'label' => Craft::t('swish-suite', 'Payments'),
                'url'   => self::CP_ROUTE_PAYMENTS,
            ],
            'refunds' => [
                'label' => Craft::t('swish-suite', 'Refunds'),
                'url'   => self::CP_ROUTE_REFUNDS,
            ],
        ];

        return $item;
    }

    private function registerTwigExtension(): void
    {
        Craft::$app->view->registerTwigExtension(new SwishSuiteExtension());
    }

    private function registerTemplateRoots(): void
    {
        $templateRoot = __DIR__ . DIRECTORY_SEPARATOR . 'templates';
        $pluginId     = $this->id;

        $register = function (RegisterTemplateRootsEvent $event) use ($pluginId, $templateRoot): void {
            $event->roots[$pluginId] = $templateRoot;
        };

        Event::on(View::class, View::EVENT_REGISTER_SITE_TEMPLATE_ROOTS, $register);
        Event::on(View::class, View::EVENT_REGISTER_CP_TEMPLATE_ROOTS, $register);
    }

    private function registerCpRoutes(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function (RegisterUrlRulesEvent $event): void {
                $event->rules[self::CP_ROUTE_BASE]              = 'swish-suite/cp/welcome/index';
                $event->rules[self::CP_ROUTE_WELCOME]           = 'swish-suite/cp/welcome/index';
                $event->rules[self::CP_ROUTE_DASHBOARD]         = 'swish-suite/cp/dashboard/index';
                $event->rules[self::CP_ROUTE_PAYMENTS]          = 'swish-suite/cp/payments/index';
                $event->rules[self::CP_ROUTE_PAYMENTS_CREATE]   = 'swish-suite/cp/payments/create';
                $event->rules[self::CP_ROUTE_PAYMENTS_STORE]    = 'swish-suite/cp/payments/store';
                $event->rules[self::CP_ROUTE_PAYMENTS . '/<id:\d+>/qr-waiting'] = 'swish-suite/cp/payments/qr-waiting';
                $event->rules[self::CP_ROUTE_PAYMENTS . '/<id:\d+>/refresh'] = 'swish-suite/cp/payments/refresh-status';
                $event->rules[self::CP_ROUTE_PAYMENTS . '/<id:\d+>']         = 'swish-suite/cp/payments/view';
                $event->rules[self::CP_ROUTE_REFUNDS]           = 'swish-suite/cp/refunds/index';
                $event->rules[self::CP_ROUTE_REFUNDS_CREATE]    = 'swish-suite/cp/refunds/create';
                $event->rules[self::CP_ROUTE_REFUNDS_STORE]     = 'swish-suite/cp/refunds/store';
                $event->rules[self::CP_ROUTE_REFUNDS . '/<id:\d+>/refresh']  = 'swish-suite/cp/refunds/refresh-status';
                $event->rules[self::CP_ROUTE_REFUNDS . '/<id:\d+>']          = 'swish-suite/cp/refunds/view';
            }
        );
    }

    private function registerCommerceIntegration(): void
    {
        \yii\base\Event::on(
            \craft\commerce\services\Gateways::class,
            \craft\commerce\services\Gateways::EVENT_REGISTER_GATEWAY_TYPES,
            function (\craft\events\RegisterComponentTypesEvent $event): void {
                $event->types[] = \NinetyNineX\SwishSuite\gateways\SwishGateway::class;
            }
        );
    }

    private function registerSiteRoutes(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_SITE_URL_RULES,
            function (RegisterUrlRulesEvent $event): void {
                $event->rules[self::SITE_ROUTE_CHECKOUT]        = 'swish-suite/payments/checkout';
                $event->rules[self::SITE_ROUTE_PAY]             = 'swish-suite/payments/pay';
                $event->rules[self::SITE_ROUTE_PAYMENT_PROCESS] = 'swish-suite/payments/process';
                $event->rules[self::SITE_ROUTE_PAYMENT_WAITING] = 'swish-suite/payments/waiting';
                $event->rules[self::SITE_ROUTE_PAYMENT_POLL]    = 'swish-suite/payments/poll-status';
                $event->rules[self::SITE_ROUTE_PAYMENT_SUCCESS] = 'swish-suite/payments/success';
                $event->rules[self::SITE_ROUTE_PAYMENT_CANCEL]  = 'swish-suite/payments/cancel';
                $event->rules[$this->getCallbackRoute()]        = 'swish-suite/callback/process';
            }
        );
    }

    public function paymentFormUrl(): string
    {
        return UrlHelper::siteUrl(self::SITE_ROUTE_PAYMENT_PROCESS);
    }

    public function isConfigured(): bool
    {
        $settings = $this->getSettings();
        $number   = App::parseEnv($settings->swishNumber);
        $cert     = App::parseEnv($settings->certPath);
        return is_string($number) && $number !== '' && !str_starts_with($number, '$')
            && is_string($cert)   && $cert   !== '' && !str_starts_with($cert, '$');
    }

    public function getCallbackRoute(): string
    {
        $callbackUri = App::parseEnv('$SWISH_CALLBACK_URI');
        $callbackUri = ($callbackUri !== null && $callbackUri !== '$SWISH_CALLBACK_URI')
            ? (string)$callbackUri
            : self::DEFAULT_CALLBACK_ROUTE;

        return ltrim($callbackUri, '/');
    }
}
