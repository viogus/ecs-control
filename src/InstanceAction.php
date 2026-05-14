<?php

declare(strict_types=1);

enum InstanceAction: string
{
    case Start = 'start';
    case Stop = 'stop';
    case Delete = 'delete';
}
