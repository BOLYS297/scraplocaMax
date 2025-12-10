<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\rental_sources as RentalSource;

class RentalSourceController extends Controller
{
    /**
     * Affiche la liste des sources de location avec filtre par ville.
     */
    public function index(Request $request)
    {
        // Récupère le filtre 'city' depuis la query string
        $city = $request->query('city');

        // Prépare la query
        $query = RentalSource::query();

        if ($city) {
            $query->where('city', $city);
        }

        // Tri par date d'ajout (récent d'abord). Change si tu veux un autre tri.
        $sources = $query->orderBy('created_at', 'desc')->paginate(20)->withQueryString();

        // Liste des villes disponibles pour le filtre (distinct)
        $cities = RentalSource::select('city')
                    ->whereNotNull('city')
                    ->distinct()
                    ->orderBy('city')
                    ->pluck('city');

        return view('rental_sources', compact('sources', 'cities', 'city'));
    }
}
