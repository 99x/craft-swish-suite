<?php

namespace NinetyNineX\SwishSuite\events;

use NinetyNineX\SwishSuite\records\Payment;
use yii\base\Event;

class PaymentEvent extends Event
{
    public Payment $payment;
}
