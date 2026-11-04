@extends('layouts.app', ['title' => 'Backlog '.$project->key])

@section('content')
<p class="mb-1 font-mono text-sm text-slate-500">{{ $project->key }}</p>
<h1 class="mb-6 text-2xl font-semibold">Backlog</h1>

@include('work::work.partials.items', ['items' => $items, 'empty' => 'Este proyecto aún no tiene elementos.'])

@if ($nextCursor)
    <a href="{{ route('work.backlog', ['organization' => $organizationId, 'project' => $project->id, 'cursor' => $nextCursor]) }}" class="mb-8 inline-block rounded-lg border border-slate-300 px-4 py-2 hover:bg-slate-100 focus:outline-none focus:ring-2 focus:ring-sky-400 dark:border-slate-600 dark:hover:bg-slate-800">Siguiente página</a>
@endif

@if ($canCreate)
<section class="mt-4 max-w-md rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-800">
    <h2 class="mb-4 text-lg font-semibold">Nuevo elemento</h2>
    <form method="POST" action="{{ route('work.store', ['organization' => $organizationId, 'project' => $project->id]) }}" class="space-y-3">
        @csrf
        <label for="title" class="block text-sm font-medium">Título</label>
        <input id="title" name="title" value="{{ old('title') }}" required maxlength="200" class="w-full rounded-lg border border-slate-300 px-3 py-2 focus:border-sky-500 focus:outline-none focus:ring-2 focus:ring-sky-200 dark:border-slate-600 dark:bg-slate-900">
        <label for="type" class="block text-sm font-medium">Tipo</label>
        <select id="type" name="type" class="w-full rounded-lg border border-slate-300 px-3 py-2 focus:border-sky-500 focus:outline-none focus:ring-2 focus:ring-sky-200 dark:border-slate-600 dark:bg-slate-900">
            @foreach ($types as $type)<option value="{{ $type }}" @selected($type === 'task')>{{ $type }}</option>@endforeach
        </select>
        <label for="priority" class="block text-sm font-medium">Prioridad</label>
        <select id="priority" name="priority" class="w-full rounded-lg border border-slate-300 px-3 py-2 focus:border-sky-500 focus:outline-none focus:ring-2 focus:ring-sky-200 dark:border-slate-600 dark:bg-slate-900">
            @foreach ($priorities as $priority)<option value="{{ $priority }}">{{ $priority }}</option>@endforeach
        </select>
        <button type="submit" class="rounded-lg bg-slate-900 px-4 py-2 font-medium text-white hover:bg-slate-800 focus:outline-none focus:ring-2 focus:ring-sky-400">Crear elemento</button>
    </form>
</section>
@endif
@endsection
