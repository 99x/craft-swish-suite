<?php

namespace NinetyNineX\SwishSuite\events;

use NinetyNineX\SwishSuite\records\Refund;
use yii\base\Event;

class RefundEvent extends Event
{
    public Refund $refund;
}
