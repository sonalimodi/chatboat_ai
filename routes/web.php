<?php

use App\Http\Controllers\ChatController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

Route::redirect('/', '/chat');

Route::prefix('chat')->name('chat.')->group(function () {
    Route::get('/', [ChatController::class, 'index'])->name('index');
    Route::post('/new', [ChatController::class, 'store'])->name('new');
    Route::get('/{chatSession}', [ChatController::class, 'show'])->name('show');
    Route::post('/{chatSession}/messages', [ChatController::class, 'sendMessage'])->name('messages.store');
    Route::post('/{chatSession}/regenerate', [ChatController::class, 'regenerate'])->name('regenerate');
    Route::delete('/{chatSession}', [ChatController::class, 'destroy'])->name('destroy');
});
