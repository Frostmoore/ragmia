<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RagSendController;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| - "/" è unico entry-point:
|   * guest  -> mostra landing pubblica
|   * authed -> serve direttamente la chat (ChatController@index)
| - Le API della chat stanno sotto /api e sono dietro auth+verified
|
*/

// Entry point unico: guest vs authed (compat con route('chat.index'))
Route::get('/', function (ChatController $chat) {
    if (auth()->check()) {
        // Utente loggato -> render chat direttamente
        return $chat->index();
    }
    // Guest -> landing pubblica
    return view('landing');
})->name('chat.index');

// Alias comuni/legacy
Route::get('/home', fn () => redirect()->route('chat.index'))->name('home');
Route::get('/dashboard', fn () => redirect()->route('chat.index'))->name('dashboard'); // ← fix per Breeze

// Gruppo autenticato
Route::middleware(['auth', 'verified'])->group(function () {

    // === API Chat (Ajax) ===
    Route::prefix('api')->group(function () {
        // Albero progetti/cartelle
        Route::get('/projects', [ChatController::class, 'listProjects'])->name('projects.list');
        // Storico messaggi di un progetto
        Route::get('/messages', [ChatController::class, 'listMessages'])->name('messages.list');

        // Crea progetto da path (Cartella/Sub/Progetto o SoloNome)
        Route::post('/projects', [ChatController::class, 'createProject'])->name('projects.create');
        // Elimina progetto
        Route::delete('/projects/{projectId}', [ChatController::class, 'deleteProject'])->name('projects.delete');

        // Crea cartella (anche annidata)
        Route::post('/folders', [ChatController::class, 'createFolder'])->name('folders.create');
        // Elimina cartella (ricorsiva)
        Route::delete('/folders/{folderId}', [ChatController::class, 'deleteFolder'])->name('folders.delete');
    });

    // Invia messaggio al modello (usa SEMPRE la tab attiva lato client)
    Route::post('/send', [ChatController::class, 'send'])->name('chat.send');

    // Stats (se vuoi tenerla privata)
    Route::get('/chat/stats', [ChatController::class, 'stats'])->name('chat.stats');

    // Endpoint RAG (protetto)
    Route::post('/rag/send', [RagSendController::class, 'send'])->name('rag.send');
});

// Profili Breeze
Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

// Auth routes Breeze
require __DIR__ . '/auth.php';
