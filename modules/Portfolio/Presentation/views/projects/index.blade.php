@extends('layouts.app', ['title' => 'Proyectos'])

@section('content')
<h1 class="mb-6 text-2xl font-semibold">Proyectos</h1>

<div class="mb-4 overflow-x-auto rounded-xl border border-slate-200 bg-white shadow-sm dark:border-slate-700 dark:bg-slate-800">
    <table class="min-w-full text-sm">
        <caption class="sr-only">Proyectos visibles para ti</caption>
        <thead class="bg-slate-50 text-left text-slate-600 dark:bg-slate-900 dark:text-slate-300">
            <tr><th scope="col" class="px-5 py-3">Clave</th><th scope="col" class="px-5 py-3">Nombre</th><th scope="col" class="px-5 py-3">Tipo</th><th scope="col" class="px-5 py-3">Estado</th><th scope="col" class="px-5 py-3">Confidencialidad</th></tr>
        </thead>
        <tbody>
        @forelse ($projects as $project)
            <tr class="border-t border-slate-100 dark:border-slate-700">
                <td class="px-5 py-3 font-mono"><a href="{{ route('projects.show', ['organization' => $organizationId, 'project' => $project->id]) }}" class="text-sky-700 underline-offset-2 hover:underline focus:outline-none focus:ring-2 focus:ring-sky-400 dark:text-sky-300">{{ $project->key }}</a></td>
                <td class="px-5 py-3">{{ $project->name }}</td>
                <td class="px-5 py-3">{{ $project->type }}</td>
                <td class="px-5 py-3">{{ $project->status }}</td>
                <td class="px-5 py-3">{{ $project->confidentiality === 'restricted' ? 'Restringido' : 'Estándar' }}</td>
            </tr>
        @empty
            <tr><td colspan="5" class="px-5 py-6 text-center text-slate-500">No tienes proyectos visibles.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>

@if ($nextCursor)
    <a href="{{ route('projects.index', ['organization' => $organizationId, 'cursor' => $nextCursor]) }}" class="mb-8 inline-block rounded-lg border border-slate-300 px-4 py-2 hover:bg-slate-100 focus:outline-none focus:ring-2 focus:ring-sky-400 dark:border-slate-600 dark:hover:bg-slate-800">Siguiente página</a>
@endif

@if ($canCreate)
<section class="mt-4 max-w-md rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-800">
    <h2 class="mb-4 text-lg font-semibold">Nuevo proyecto</h2>
    <form method="POST" action="{{ route('projects.store', ['organization' => $organizationId]) }}" class="space-y-3">
        @csrf
        <label for="key" class="block text-sm font-medium">Clave</label>
        <input id="key" name="key" value="{{ old('key') }}" required pattern="[A-Z][A-Z0-9]{1,9}" maxlength="10" aria-describedby="key-help" class="w-full rounded-lg border border-slate-300 px-3 py-2 font-mono uppercase focus:border-sky-500 focus:outline-none focus:ring-2 focus:ring-sky-200 dark:border-slate-600 dark:bg-slate-900">
        <p id="key-help" class="text-xs text-slate-500">2 a 10 mayúsculas o dígitos, empieza con letra. No se puede cambiar.</p>
        <label for="name" class="block text-sm font-medium">Nombre</label>
        <input id="name" name="name" value="{{ old('name') }}" required maxlength="120" class="w-full rounded-lg border border-slate-300 px-3 py-2 focus:border-sky-500 focus:outline-none focus:ring-2 focus:ring-sky-200 dark:border-slate-600 dark:bg-slate-900">
        <label for="type" class="block text-sm font-medium">Tipo</label>
        <select id="type" name="type" class="w-full rounded-lg border border-slate-300 px-3 py-2 focus:border-sky-500 focus:outline-none focus:ring-2 focus:ring-sky-200 dark:border-slate-600 dark:bg-slate-900">
            <option value="general">General</option><option value="software">Software</option><option value="hybrid">Híbrido</option>
        </select>
        <label for="confidentiality" class="block text-sm font-medium">Confidencialidad</label>
        <select id="confidentiality" name="confidentiality" class="w-full rounded-lg border border-slate-300 px-3 py-2 focus:border-sky-500 focus:outline-none focus:ring-2 focus:ring-sky-200 dark:border-slate-600 dark:bg-slate-900">
            <option value="standard">Estándar</option><option value="restricted">Restringido (solo miembros directos)</option>
        </select>
        <button type="submit" class="rounded-lg bg-slate-900 px-4 py-2 font-medium text-white hover:bg-slate-800 focus:outline-none focus:ring-2 focus:ring-sky-400">Crear proyecto</button>
    </form>
</section>
@endif
@endsection
