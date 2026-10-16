<?php

namespace App\Exceptions;

/** تحويل آخر يعمل الآن (تزامن 1 على الحاوية): 503 والواجهة تعرض «أعد المحاولة» بالتسجيل نفسه */
class ConverterBusyException extends VoiceNoteException
{
    public function __construct()
    {
        parent::__construct('CONVERTER_BUSY', 'المحوّل مشغول، أعد المحاولة بعد ثوانٍ.', 503);
    }
}
