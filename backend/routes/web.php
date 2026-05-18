<?php

use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use App\Jobs\SendNotificationJob;

Route::get('/', function () {
    return view('welcome');
});

