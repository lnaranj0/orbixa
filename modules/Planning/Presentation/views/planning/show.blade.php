@extends('layouts.app', ['title' => 'Planificación'])

@section('content')
<h1 class="mb-2 text-2xl font-semibold">Planificación</h1>
<p class="mb-6 text-sm text-slate-500">Modo: <strong>{{ ['continuous' => 'Flujo continuo', 'sprint' => 'Sprints', 'hybrid' => 'Híbrido'][$mode] }}</strong>. Los ciclos se gestionan mediante la API (<code>/projects/{project}/cycles</code>).</p>

<div class="overflow-x-auto rounded-xl border border-slate-200 bg-white shadow-sm dark:border-slate-700 dark:bg-slate-800">
    <table class="min-w-full text-sm">
        <caption class="sr-only">Ciclos del proyecto</caption>
        <thead class="bg-slate-50 text-left text-slate-600 dark:bg-slate-900 dark:text-slate-300">
            <tr><th scope="col" class="px-5 py-3">Ciclo</th><th scope="col" class="px-5 py-3">Fechas</th><th scope="col" class="px-5 py-3">Capacidad</th><th scope="col" class="px-5 py-3">Estado</th></tr>
        </thead>
        <tbody>
        @forelse ($cycles as $cycle)
            <tr class="border-t border-slate-100 dark:border-slate-700">
                <td class="px-5 py-3">{{ $cycle['name'] }}@if ($cycle['goal'])<span class="block text-xs text-slate-500">{{ $cycle['goal'] }}</span>@endif</td>
                <td class="px-5 py-3">{{ $cycle['starts_on'] }} → {{ $cycle['ends_on'] }}</td>
                <td class="px-5 py-3">{{ $cycle['capacity'] ?? '—' }}</td>
                <td class="px-5 py-3">{{ ['planned' => 'Planificado', 'active' => 'Activo', 'closed' => 'Cerrado'][$cycle['status']] }}</td>
            </tr>
        @empty
            <tr><td colspan="4" class="px-5 py-6 text-center text-slate-500">{{ $mode === 'continuous' ? 'Proyecto en flujo continuo: no usa ciclos.' : 'Aún no hay ciclos.' }}</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@endsection
