<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// The till's own sign-in. Not the panel's: that one refuses cashiers outright,
// because it will not authenticate anybody it would then have to keep out.
Route::livewire('/login', 'pages::login')->middleware('guest')->name('login');

Route::livewire('/pos', 'pages::pos')->middleware('auth')->name('pos');
