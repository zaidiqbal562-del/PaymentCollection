<?php

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\CourseController;
use App\Http\Controllers\OrderController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/dashboard', function () {
    return view('dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

Route::get('/courses', [CourseController::class, 'bladeIndex'])->name('courses.index');

Route::middleware(['auth', 'can:manage-courses'])->group(function () {
    Route::get('/courses/create', [CourseController::class, 'bladeCreate'])->name('courses.create');
    Route::post('/courses', [CourseController::class, 'bladeStore'])->name('courses.store');
    Route::get('/courses/{id}/edit', [CourseController::class, 'bladeEdit'])->name('courses.edit');
    Route::put('/courses/{id}', [CourseController::class, 'bladeUpdate'])->name('courses.update');
    Route::delete('/courses/{id}', [CourseController::class, 'bladeDestroy'])->name('courses.destroy');
});

Route::middleware(['auth'])->group(function () {
    Route::post('/orders', [OrderController::class, 'store'])->name('orders.store')->middleware('throttle:10,1');
    Route::post('/orders/{orderNumber}/verify-payment', [OrderController::class, 'verifyPayment'])->name('orders.verify')->middleware('throttle:10,1');
    Route::get('/orders/{orderNumber}/status', [OrderController::class, 'status'])->name('orders.status')->middleware('throttle:30,1');
});

require __DIR__.'/auth.php';
