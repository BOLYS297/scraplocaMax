<?php

use Illuminate\Support\Facades\Route;
use Spatie\Browsershot\Browsershot;
use App\Http\Controllers\RentalSourceController;


Route::get('/', function () {
    return redirect()->route('rental.sources.index');
});


Route::get('/browsertest', function () {
    Browsershot::url('https://google.com')
        ->setDelay(2000) // laisse le JS s’exécuter
        ->save('google-test.png');

    return "Screenshot généré → google-test.png ✔";
});


Route::get('/rental-sources', [RentalSourceController::class, 'index'])->name('rental.sources.index');

