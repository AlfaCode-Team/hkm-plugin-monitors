<?php

declare(strict_types=1);

namespace Plugins\Monitors\Domain;

enum AlertKind: string
{
    case Fired    = 'fired';
    case Resolved = 'resolved';
}
