<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ChatController;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
*/

// Home → Chat (protetta da login)
Route::get('/', [ChatController::class, 'index'])
    ->middleware(['auth', 'verified'])
    ->name('chat.index');

// Dashboard Breeze (se ti serve tenerla)
Route::get('/dashboard', function () {
    return view('dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

// API Chat (Ajax) — tutte dietro login
Route::middleware(['auth', 'verified'])->group(function () {
    // albero progetti/cartelle
    Route::get('/api/projects', [ChatController::class, 'listProjects'])->name('projects.list');
    // storico messaggi di un progetto
    Route::get('/api/messages', [ChatController::class, 'listMessages'])->name('messages.list');
    // crea progetto da path (Cartella/Sub/Progetto o SoloNome)
    Route::post('/api/projects', [ChatController::class, 'createProject'])->name('projects.create');
    // crea cartella (anche annidata)
    Route::post('/api/folders', [ChatController::class, 'createFolder'])->name('folders.create');

    // invia messaggio al modello (usa SEMPRE la tab attiva lato client)
    Route::post('/send', [ChatController::class, 'send'])->name('chat.send');
});

Route::get('/chat/stats', [\App\Http\Controllers\ChatController::class, 'stats'])
    ->middleware(['auth']) // se vuoi proteggerla
    ->name('chat.stats');



// Profili Breeze
Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

// Auth routes Breeze
require __DIR__.'/auth.php';
