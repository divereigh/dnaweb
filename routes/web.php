<?php

use App\Http\Controllers\CommonMatchesController;
use App\Http\Controllers\DnaController;
use App\Http\Controllers\DnaMatchesController;
use App\Http\Controllers\DnaNoteController;
use App\Http\Controllers\EyesController;
use App\Http\Controllers\EyeMatchesController;
use App\Http\Controllers\PeopleController;
use App\Http\Controllers\PersonController;
use App\Http\Controllers\PersonTreeController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\TreeController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/eyes')->name('home');
Route::redirect('/dashboard', '/eyes')->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/eyes', [EyesController::class, 'index'])->name('eyes.index');
    // Soft-deleted 2026-05-22: superseded by /dna/{id}/matches (which handles both eyes and non-eyes).
    // Controllers, services and Vue pages retained in case we need to revive these.
    // Route::get('/eye/{id}/matches', [EyeMatchesController::class, 'index'])->name('eyes.matches');
    // Route::get('/eye/{id}/match/{otherId}/common', [CommonMatchesController::class, 'index'])->name('common.index');

    Route::get('/people', [PeopleController::class, 'index'])->name('people.index');
    Route::get('/person/{id}', [PersonController::class, 'show'])->name('people.show');
    Route::get('/person/{id}/tree', [PersonTreeController::class, 'show'])->name('people.tree');

    Route::get('/dna', [DnaController::class, 'index'])->name('dna.index');
    Route::get('/dna/{id}/matches', [DnaMatchesController::class, 'index'])->name('dna.matches');
    Route::post('/dna/{id}/matches/requeue', [DnaMatchesController::class, 'requeue'])->name('dna.matches.requeue');
    Route::put('/dna/notes/{sample}/{mgmtsample}', [DnaNoteController::class, 'update'])->name('dna.notes.update');
    Route::put('/dna/trees/{tree}', [TreeController::class, 'update'])->name('dna.trees.update');
    Route::put('/dna/{sampleId}/person', [PersonController::class, 'upsertForSample'])->name('dna.person.upsert');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
