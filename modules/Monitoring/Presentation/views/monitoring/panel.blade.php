@extends('layouts.app', ['title' => 'Monitoreo'])

@section('content')
<div class="mb-6 flex flex-wrap items-center justify-between gap-3">
    <h1 class="text-2xl font-semibold">Monitoreo</h1>
    @if ($canAdminister)
        <a href="{{ route('monitoring.grants', ['organization' => $organizationId]) }}" class="rounded-lg border border-slate-300 px-4 py-2 hover:bg-slate-100 focus:outline-none focus:ring-2 focus:ring-sky-400 dark:border-slate-600 dark:hover:bg-slate-800">Concesiones</a>
    @endif
</div>

<p class="mb-4 text-sm text-slate-500">Vista de solo lectura. Solo incluye el trabajo que cubren tus concesiones vigentes.</p>

<div class="mb-6 grid grid-cols-2 gap-4 sm:grid-cols-4">
    <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-700 dark:bg-slate-800">
        <p class="text-sm text-slate-500">En tu alcance</p>
        <p class="text-2xl font-semibold">{{ $counts['total'] }}</p>
    </div>
    @foreach (['active' => 'En curso', 'review' => 'En revisión', 'done' => 'Hechos'] as $status => $label)
        <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-700 dark:bg-slate-800">
            <p class="text-sm text-slate-500">{{ $label }}</p>
            <p class="text-2xl font-semibold">{{ $counts['by_status'][$status] ?? 0 }}</p>
        </div>
    @endforeach
</div>

<div class="overflow-x-auto rounded-xl border border-slate-200 bg-white shadow-sm dark:border-slate-700 dark:bg-slate-800">
    <table class="min-w-full text-sm">
        <caption class="sr-only">Trabajo en tu alcance de monitoreo</caption>
        <thead class="bg-slate-50 text-left text-slate-600 dark:bg-slate-900 dark:text-slate-300">
            <tr><th scope="col" class="px-5 py-3">Clave</th><th scope="col" class="px-5 py-3">Título</th><th scope="col" class="px-5 py-3">Estado</th><th scope="col" class="px-5 py-3">Prioridad</th></tr>
        </thead>
        <tbody>
        @forelse ($items as $item)
            <tr class="border-t border-slate-100 dark:border-slate-700">
                <td class="px-5 py-3 font-mono"><a href="{{ route('monitoring.item', ['organization' => $organizationId, 'item' => $item->id]) }}" class="text-sky-700 underline-offset-2 hover:underline focus:outline-none focus:ring-2 focus:ring-sky-400 dark:text-sky-300">{{ $item->key }}</a></td>
                <td class="px-5 py-3">{{ $item->title }}</td>
                <td class="px-5 py-3">{{ $item->status }}</td>
                <td class="px-5 py-3">{{ $item->priority }}</td>
            </tr>
        @empty
            <tr><td colspan="4" class="px-5 py-6 text-center text-slate-500">No hay trabajo en tu alcance de monitoreo.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@endsection
