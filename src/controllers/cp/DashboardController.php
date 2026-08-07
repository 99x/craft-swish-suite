<?php

namespace NinetyNineX\SwishSuite\controllers\cp;

use craft\web\Controller;
use NinetyNineX\SwishSuite\records\Payment;
use NinetyNineX\SwishSuite\SwishSuite;
use yii\web\Response;

class DashboardController extends Controller
{
    public function actionIndex(): Response
    {
        $this->requireCpRequest();

        $tenMinutesAgo = gmdate('Y-m-d H:i:s', time() - 600);
        $oneDayAgo = gmdate('Y-m-d H:i:s', time() - 86400);
        $totalPayments = (int)Payment::find()->count();
        $statusCounts = [
            Payment::STATUS_CREATED => (int)Payment::find()->where(['status' => Payment::STATUS_CREATED])->count(),
            Payment::STATUS_PAID => (int)Payment::find()->where(['status' => Payment::STATUS_PAID])->count(),
            Payment::STATUS_DECLINED => (int)Payment::find()->where(['status' => Payment::STATUS_DECLINED])->count(),
            Payment::STATUS_CANCELLED => (int)Payment::find()->where(['status' => Payment::STATUS_CANCELLED])->count(),
            Payment::STATUS_ERROR => (int)Payment::find()->where(['status' => Payment::STATUS_ERROR])->count(),
        ];
        $paidMinorUnits = (int)(Payment::find()
            ->where(['status' => Payment::STATUS_PAID])
            ->sum('amount') ?? 0);
        $recentPayments = Payment::find()->orderBy(['dateCreated' => SORT_DESC])->limit(10)->all();
        $stuckCreatedCount = (int)Payment::find()
            ->where(['status' => Payment::STATUS_CREATED])
            ->andWhere(['<', 'dateCreated', $tenMinutesAgo])
            ->count();
        $recentErrorCount = (int)Payment::find()
            ->where(['status' => [Payment::STATUS_ERROR, Payment::STATUS_DECLINED]])
            ->andWhere(['>=', 'dateCreated', $oneDayAgo])
            ->count();

        // Use resolved configuration values for accurate completeness check
        $resolvedConfig = SwishSuite::getInstance()->swishPayment->getResolvedConfiguration();
        $settings = SwishSuite::getInstance()->getSettings();
        $configWarnings = [];

        if ($resolvedConfig['merchantNumber'] === '' || $resolvedConfig['certPath'] === '' || $resolvedConfig['caPath'] === '') {
            $configWarnings[] = 'Core Swish configuration is incomplete. Check Settings and Diagnostics.';
        }
        if ($settings->testMode) {
            $configWarnings[] = 'Plugin is currently using the MSS test environment.';
        }

        return $this->renderTemplate('swish-suite/cp/dashboard/index', [
            'totalPayments' => $totalPayments,
            'statusCounts' => $statusCounts,
            'paidAmount' => SwishSuite::getInstance()->swishPayment->formatAmountForDisplay($paidMinorUnits),
            'recentPayments' => $recentPayments,
            'stuckCreatedCount' => $stuckCreatedCount,
            'recentErrorCount' => $recentErrorCount,
            'configWarnings' => $configWarnings,
        ]);
    }
}
