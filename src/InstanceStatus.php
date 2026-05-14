<?php

declare(strict_types=1);

enum InstanceStatus: string
{
    case Running = 'Running';
    case Stopped = 'Stopped';
    case Starting = 'Starting';
    case Stopping = 'Stopping';
    case Pending = 'Pending';
    case Released = 'Released';
    case Releasing = 'Releasing';
    case Unknown = 'Unknown';

    public function isStable(): bool
    {
        return in_array($this, [self::Running, self::Stopped], true);
    }

    public function isTransient(): bool
    {
        return in_array($this, [self::Starting, self::Stopping, self::Pending, self::Unknown], true);
    }
}
