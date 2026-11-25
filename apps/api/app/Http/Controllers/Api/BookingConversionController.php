<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\BookingConversion;

class BookingConversionController extends Controller
{
    public function mark(string $token)
    {
        return response()->json(BookingConversion::mark($token));
    }
}
