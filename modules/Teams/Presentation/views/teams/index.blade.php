@extends('layouts.app', ['title' => 'Equipos'])

@section('content')
<h1 class="mb-6 text-2xl font-semibold">Equipos</h1>

<div class="mb-4 overflow-x-auto rounded-xl border border-slate-200 bg-white shadow-sm dark:border-slate-700 dark:bg-slate-800">
    <table class="min-w-full text-sm">
        <caption class="sr-only">Equipos visibles para ti</caption>
        <thead class="bg-slate-50 text-left text-slate-600 dark:bg-slate-900 dark:text-slate-300">
            <tr><th scope="col" class="px-5 py-3">Nombre</th><th scope="col" class="px-5 py-3">Equipo padre</th><th scope="col" class="px-5 py-3">Estado</th></tr>
        </thead>
        <tbody>
        @forelse ($teams as $team)
            <tr class="border-t border-slate-100 dark:border-slate-700">
                <td class="px-5 py-3">{{ $team['name'] }}</td>
                <td class="px-5 py-3">{{ $team['parent_team_id'] === null ? '—' : ($names[$team['parent_team_id']] ?? 'Otro equipo') }}</td>
                <td class="px-5 py-3">{{ $team['status'] === 'active' ? 'Activo' : 'Archivado' }}</td>
            </tr>
        @empty
            <tr><td colspan="3" class="px-5 py-6 text-center text-slate-500">No perteneces a ningún equipo.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>

@if ($nextCursor)
    <a href="{{ route('teams.index', ['organization' => $organizationId, 'cursor' => $nextCursor]) }}" class="mb-8 inline-block rounded-lg border border-slate-300 px-4 py-2 hover:bg-slate-100 focus:outline-none focus:ring-2 focus:ring-sky-400 dark:border-slate-600 dark:hover:bg-slate-800">Siguiente página</a>
@endif

@if ($canCreate)
<section class="mt-4 max-w-md rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-800">
    <h2 class="mb-4 text-lg font-semibold">Nuevo equipo</h2>
    <form method="POST" action="{{ route('teams.store', ['organization' => $organizationId]) }}" class="space-y-3">
        @csrf
        <label for="name" class="block text-sm font-medium">Nombre</label>
        <input id="name" name="name" value="{{ old('name') }}" required maxlength="120" class="w-full rounded-lg border border-slate-300 px-3 py-2 focus:border-sky-500 focus:outline-none focus:ring-2 focus:ring-sky-200 dark:border-slate-600 dark:bg-slate-900">
        <label for="parent_team_id" class="block text-sm font-medium">Equipo padre (opcional)</label>
        <select id="parent_team_id" name="parent_team_id" class="w-full rounded-lg border border-slate-300 px-3 py-2 focus:border-sky-500 focus:outline-none focus:ring-2 focus:ring-sky-200 dark:border-slate-600 dark:bg-slate-900">
            <option value="">Ninguno (equipo raíz)</option>
            @foreach ($teams as $team)
                @if ($team['status'] === 'active')<option value="{{ $team['id'] }}">{{ $team['name'] }}</option>@endif
            @endforeach
        </select>
        <button type="submit" class="rounded-lg bg-slate-900 px-4 py-2 font-medium text-white hover:bg-slate-800 focus:outline-none focus:ring-2 focus:ring-sky-400">Crear equipo</button>
    </form>
</section>
@endif
@endsection
