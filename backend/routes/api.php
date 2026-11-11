<?php

use App\Http\Controllers\Api\AdminUserController;
use App\Http\Controllers\Api\AttachmentController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ChannelController;
use App\Http\Controllers\Api\ClientController;
use App\Http\Controllers\Api\MemberController;
use App\Http\Controllers\Api\MessageController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\PushSubscriptionController;
use App\Http\Controllers\Api\SearchController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\WatchController;
use Illuminate\Support\Facades\Route;

// ---------------------------------------------------------------- public
Route::prefix('auth')->middleware('throttle:auth')->group(function () {
    Route::post('login', [AuthController::class, 'login']);
    Route::post('forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('reset-password', [AuthController::class, 'resetPassword']);
    Route::post('accept-invite', [AuthController::class, 'acceptInvite']);
});

// Signed, short-lived file URLs (see POST /attachments/urls). No token needed so <img src> works.
Route::get('files/{attachment}', [AttachmentController::class, 'file'])
    ->middleware('signed')->name('attachments.file');

Route::get('push/public-key', [PushSubscriptionController::class, 'publicKey']);

// --------------------------------------------------------- signed in
Route::middleware(['auth:sanctum', 'active'])->group(function () {
    Route::post('auth/logout', [AuthController::class, 'logout']);

    Route::get('me', [ProfileController::class, 'show']);
    Route::patch('me', [ProfileController::class, 'update']);
    Route::put('me/password', [ProfileController::class, 'updatePassword']);

    Route::get('users', [UserController::class, 'index']);

    Route::get('clients', [ClientController::class, 'index']);
    Route::post('clients', [ClientController::class, 'store']);
    Route::get('clients/{client}', [ClientController::class, 'show']);
    Route::patch('clients/{client}', [ClientController::class, 'update']);
    Route::put('clients/{client}/archived', [ClientController::class, 'setArchived']);
    Route::get('clients/{client}/members', [MemberController::class, 'clientIndex']);
    Route::post('clients/{client}/members', [MemberController::class, 'clientStore']);
    Route::patch('clients/{client}/members/{user}', [MemberController::class, 'clientUpdate']);
    Route::delete('clients/{client}/members/{user}', [MemberController::class, 'clientDestroy']);

    Route::get('channels', [ChannelController::class, 'index']);
    Route::post('channels', [ChannelController::class, 'store']);
    Route::get('channels/{channel}', [ChannelController::class, 'show']);
    Route::patch('channels/{channel}', [ChannelController::class, 'update']);
    Route::put('channels/{channel}/archived', [ChannelController::class, 'setArchived']);
    Route::get('channels/{channel}/members', [MemberController::class, 'channelIndex']);
    Route::post('channels/{channel}/members', [MemberController::class, 'channelStore']);
    Route::patch('channels/{channel}/members/{user}', [MemberController::class, 'channelUpdate']);
    Route::delete('channels/{channel}/members/{user}', [MemberController::class, 'channelDestroy']);
    Route::post('channels/{channel}/read', [ChannelController::class, 'markRead']);
    Route::put('channels/{channel}/watch', [WatchController::class, 'store']);
    Route::delete('channels/{channel}/watch', [WatchController::class, 'destroy']);

    Route::post('dms', [ChannelController::class, 'dm']);
    Route::get('unread', [ChannelController::class, 'unread']);
    Route::get('watches', [WatchController::class, 'index']);

    Route::get('channels/{channel}/messages', [MessageController::class, 'index']);
    Route::post('channels/{channel}/messages', [MessageController::class, 'store'])->middleware('throttle:messages');
    Route::patch('messages/{message}', [MessageController::class, 'update']);
    Route::delete('messages/{message}', [MessageController::class, 'destroy']);

    Route::post('attachments/urls', [AttachmentController::class, 'urls']);
    Route::delete('attachments/{attachment}', [AttachmentController::class, 'destroy']);

    Route::get('search', SearchController::class);

    Route::post('push-subscriptions', [PushSubscriptionController::class, 'store']);
    Route::patch('push-subscriptions', [PushSubscriptionController::class, 'update']);
    Route::delete('push-subscriptions', [PushSubscriptionController::class, 'destroy']);

    Route::prefix('admin/users')->middleware('admin')->group(function () {
        Route::get('/', [AdminUserController::class, 'index']);
        Route::post('/', [AdminUserController::class, 'invite']);
        Route::post('{user}/resend-invite', [AdminUserController::class, 'resendInvite']);
        Route::post('{user}/password-reset', [AdminUserController::class, 'sendPasswordReset']);
        Route::post('{user}/deactivate', [AdminUserController::class, 'deactivate']);
        Route::post('{user}/reactivate', [AdminUserController::class, 'reactivate']);
        Route::put('{user}/role', [AdminUserController::class, 'setRole']);
    });
});
