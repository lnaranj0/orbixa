@extends('layouts.app', ['title' => 'Workflow'])

@section('content')
<h1 class="mb-2 text-2xl font-semibold">Workflow del proyecto</h1>
<p class="mb-6 text-sm text-slate-500">Versión {{ $version }}. Los cambios se hacen mediante la API (<code>/projects/{project}/workflow</code>) y quedan auditados.</p>

<div class="grid gap-6 lg:grid-cols-2">
    <section class="overflow-x-auto rounded-xl border border-slate-200 bg-white shadow-sm dark:border-slate-700 dark:bg-slate-800">
        <table class="min-w-full text-sm">
            <caption class="px-5 pt-4 text-left font-semibold">Estados</caption>
            <thead class="text-left text-slate-600 dark:text-slate-300">
                <tr><th scope="col" class="px-5 py-3">Nombre</th><th scope="col" class="px-5 py-3">Categoría</th><th scope="col" class="px-5 py-3">Límite WIP</th></tr>
            </thead>
            <tbody>
            @foreach ($statuses as $status)
                <tr class="border-t border-slate-100 dark:border-slate-700 {{ $status['archived_at'] ? 'text-slate-400' : '' }}">
                    <td class="px-5 py-3">{{ $status['name'] }}{{ $status['is_initial'] ? ' (inicial)' : '' }}{{ $status['archived_at'] ? ' (archivado)' : '' }}</td>
                    <td class="px-5 py-3">{{ $status['category'] }}</td>
                    <td class="px-5 py-3">{{ $status['wip_limit'] ?? '—' }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </section>

    <section class="overflow-x-auto rounded-xl border border-slate-200 bg-white shadow-sm dark:border-slate-700 dark:bg-slate-800">
        <table class="min-w-full text-sm">
            <caption class="px-5 pt-4 text-left font-semibold">Transiciones</caption>
            <thead class="text-left text-slate-600 dark:text-slate-300">
                <tr><th scope="col" class="px-5 py-3">Desde</th><th scope="col" class="px-5 py-3">Hacia</th><th scope="col" class="px-5 py-3">Reglas</th></tr>
            </thead>
            <tbody>
            @foreach ($transitions as $transition)
                <tr class="border-t border-slate-100 dark:border-slate-700">
                    <td class="px-5 py-3">{{ $names[$transition['from_status_id']] ?? '—' }}</td>
                    <td class="px-5 py-3">{{ $names[$transition['to_status_id']] ?? '—' }}</td>
                    <td class="px-5 py-3 text-slate-600 dark:text-slate-300">
                        {{ $transition['required_permission'] === 'work.approve' ? 'Requiere aprobación' : 'Transición' }}{{ $transition['requires_reason'] ? ' · motivo' : '' }}{{ $transition['check_blockers'] ? ' · sin bloqueos' : '' }}
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </section>
</div>
@endsection
