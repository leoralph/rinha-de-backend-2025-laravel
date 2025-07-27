<?php

use App\Http\Controllers\PaymentController;
use App\Models\Payment;
use Illuminate\Support\Facades\Route;

Route::get('payments-summary', [PaymentController::class, 'index']);
Route::post('payments', [PaymentController::class, 'store']);
Route::post('purge-payments', function () {
    cache()->clear();
});