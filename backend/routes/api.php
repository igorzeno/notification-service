<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\NotificationController;

Route::post('/notifications/bulk', [NotificationController::class, 'bulkSend']);
Route::get('/subscribers/{id}/notifications', [NotificationController::class, 'subscriberHistory']);
