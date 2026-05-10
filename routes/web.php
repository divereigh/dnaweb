<?php

use App\Http\Controllers\CommonMatchesController;
use App\Http\Controllers\DnaController;
use App\Http\Controllers\DnaMatchesController;
use App\Http\Controllers\EyesController;
use App\Http\Controllers\EyeMatchesController;
use App\Http\Controllers\PeopleController;
use App\Http\Controllers\PersonController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/eyes')->name('home');
Route::redirect('/dashboard', '/eyes')->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/eyes', [EyesController::class, 'index'])->name('eyes.index');
    Route::get('/eye/{id}/matches', [EyeMatchesController::class, 'index'])->name('eyes.matches');
    Route::get('/eye/{id}/match/{otherId}/common', [CommonMatchesController::class, 'index'])->name('common.index');

    Route::get('/people', [PeopleController::class, 'index'])->name('people.index');
    Route::get('/person/{id}', [PersonController::class, 'show'])->name('people.show');

    Route::get('/dna', [DnaController::class, 'index'])->name('dna.index');
    Route::get('/dna/{id}/matches', [DnaMatchesController::class, 'index'])->name('dna.matches');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
