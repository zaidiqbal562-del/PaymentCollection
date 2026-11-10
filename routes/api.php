<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\CourseController;
use App\Http\Controllers\RazorpayWebhookController;

Route::post('/webhooks/razorpay', RazorpayWebhookController::class)
	->name('webhooks.razorpay');

// Get all courses
Route::get('/courses', [CourseController::class, 'index']);

// Get a single course
Route::get('/courses/{id}', [CourseController::class, 'show']);

Route::middleware(['auth:sanctum', 'can:manage-courses'])->group(function () {
	Route::post('/courses', [CourseController::class, 'store']);
	Route::put('/courses/{id}', [CourseController::class, 'update']);
	Route::patch('/courses/{id}', [CourseController::class, 'update']);
	Route::delete('/courses/{id}', [CourseController::class, 'destroy']);
});