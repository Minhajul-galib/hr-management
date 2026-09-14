<?php

use App\Http\Controllers\CliftoTalkController;
use App\Services\CliftoTalk;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Clifto Talk
|--------------------------------------------------------------------------
| The chat dock is mounted on every authenticated page, so these endpoints are
| hit from anywhere in the app, not just the dedicated page.
|
| Prefix, route-name prefix and middleware all come from config/clifto-talk.php
| so the module can be remounted in a host app without editing this file.
*/

Route::middleware(CliftoTalk::middleware())
    ->prefix(CliftoTalk::prefix())
    ->name(CliftoTalk::prefix().'.')
    ->group(function () {
        $c = CliftoTalkController::class;

        Route::get('/', [$c, 'index'])->name('index');

        Route::get('/conversations', [$c, 'conversations'])->name('conversations.index');
        Route::post('/conversations', [$c, 'storeConversation'])->name('conversations.store');
        Route::get('/conversations/{conversation}', [$c, 'showConversation'])->name('conversations.show');
        Route::post('/conversations/{conversation}', [$c, 'updateConversation'])->name('conversations.update');
        Route::patch('/conversations/{conversation}/preferences', [$c, 'updatePreferences'])->name('conversations.preferences');

        Route::get('/conversations/{conversation}/messages', [$c, 'messages'])->name('messages.index');
        Route::post('/conversations/{conversation}/messages', [$c, 'storeMessage'])->name('messages.store');
        Route::post('/conversations/{conversation}/read', [$c, 'markRead'])->name('conversations.read');

        Route::patch('/messages/{message}', [$c, 'updateMessage'])->name('messages.update');
        Route::delete('/messages/{message}', [$c, 'destroyMessage'])->name('messages.destroy');
        Route::post('/messages/{message}/reactions', [$c, 'toggleReaction'])->name('messages.reactions');

        Route::post('/conversations/{conversation}/participants', [$c, 'addParticipants'])->name('participants.store');
        Route::delete('/conversations/{conversation}/participants/{user}', [$c, 'removeParticipant'])->name('participants.destroy');
        Route::patch('/conversations/{conversation}/participants/{user}', [$c, 'updateParticipantRole'])->name('participants.role');

        Route::get('/users', [$c, 'users'])->name('users');
        Route::get('/search', [$c, 'search'])->name('search');
        Route::get('/unread-count', [$c, 'unreadCount'])->name('unread-count');
        Route::get('/attachments/{attachment}', [$c, 'showAttachment'])->name('attachments.show');
    });
