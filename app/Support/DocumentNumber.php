<?php

namespace App\Support;

use Illuminate\Support\Str;

final class DocumentNumber
{
    public static function make(string $prefix): string
    {
        return $prefix.'-'.now('Asia/Jakarta')->format('Ymd').'-'.Str::upper(Str::random(6));
    }
}
