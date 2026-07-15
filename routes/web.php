<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::livewire('/pos', 'pages::pos')->middleware('auth')->name('pos');
