<?php

namespace App\Enums;

enum AuditSeverity: string
{
    case Info = 'info';
    case Warn = 'warn';
    case Error = 'error';
}
