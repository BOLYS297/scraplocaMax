<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Liste des locations scrappées</title>

    <style>
        body { font-family: Arial, sans-serif; background:#f7f7f7; margin:0; padding:20px; }
        h1 { margin-bottom: 20px; text-align:center; }
        table { width:100%; border-collapse: collapse; background:white; box-shadow:0 0 8px rgba(0,0,0,0.1); }
        th, td { border:1px solid #ddd; padding:8px; font-size:14px; }
        th { background:#222; color:white; text-transform:uppercase; font-size:13px; }
        tr:nth-child(even) { background:#fafafa; }
        a{ color:#0066ff; text-decoration:none; }
        .filter-box{ margin-bottom:15px; display:flex; gap:10px; align-items:center; }
        select{ padding:6px; }
        .reset-btn{ font-size:13px; text-decoration:underline; color:#c00; }
    </style>
</head>
<body>

<h1> Résultats du Scraping</h1>

{{--  FILTRE PAR VILLE --}}
<form method="GET" class="filter-box">
    <label>Filtrer par ville :</label>

    <select name="city" onchange="this.form.submit()">
        <option value="">-- Toutes --</option>
        @foreach($cities as $c)
            <option value="{{ $c }}" @if(request('city') == $c) selected @endif>
                {{ ucfirst($c) }}
            </option>
        @endforeach
    </select>

    @if(request('city'))
        <a href="{{ url('/rental-sources') }}" class="reset-btn">Réinitialiser</a>
    @endif
</form>

{{--  TABLEAU --}}
<table>
    <thead>
        <tr>
            <th>ID</th>
            <th>URL</th>
            <th>Type Source</th>
            <th>Titre/Nom</th>
            <th>Téléphone</th>
            <th>Email</th>
            <th>Type Bien</th>
            <th>Ville</th>
            <th>Quartier</th>
            <th>Qualifié</th>
            <th>Date Ajout</th>
        </tr>
    </thead>

    <tbody>
    @forelse($sources as $s)
        <tr>
            <td>{{ $s->id }}</td>
            <td><a href="{{ $s->source_url }}" target="_blank">{{ Str::limit($s->source_url,50) }}</a></td>
            <td>{{ $s->source_type }}</td>
            <td>{{ $s->name_or_title }}</td>
            <td>{{ $s->phone_number ?? '-' }}</td>
            <td>{{ $s->email ?? '-' }}</td>
            <td>{{ $s->property_type ?? '-' }}</td>
            <td>{{ $s->city }}</td>
            <td>{{ $s->district ?? '-' }}</td>
            <td>{{ $s->is_qualified ? 'Oui' : 'Non' }}</td>
            <td>{{ $s->created_at->format('Y-m-d') }}</td>
        </tr>
    @empty
        <tr><td colspan="11" style="text-align:center;padding:10px;">Aucune donnée trouvée</td></tr>
    @endforelse
    </tbody>
</table>

{{--  PAGINATION --}}
<div style="margin-top:15px;">
    {{ $sources->links() }}
</div>

</body>
</html>
