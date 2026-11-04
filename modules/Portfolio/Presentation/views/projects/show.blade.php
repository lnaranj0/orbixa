@extends('layouts.app', ['title' => $project->name])

@section('content')
<p class="mb-1 font-mono text-sm text-slate-500">{{ $project->key }}</p>
<h1 class="mb-6 text-2xl font-semibold">{{ $project->name }}</h1>

<section class="max-w-2xl rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-800">
    <dl class="grid grid-cols-1 gap-4 text-sm sm:grid-cols-3">
        <div><dt class="text-slate-500">Tipo</dt><dd class="font-medium">{{ $project->type }}</dd></div>
        <div><dt class="text-slate-500">Estado</dt><dd class="font-medium">{{ $project->status }}</dd></div>
        <div><dt class="text-slate-500">Confidencialidad</dt><dd class="font-medium">{{ $project->confidentiality === 'restricted' ? 'Restringido' : 'Estándar' }}</dd></div>
    </dl>
    @if ($project->description)
        <p class="mt-4 whitespace-pre-line text-slate-700 dark:text-slate-300">{{ $project->description }}</p>
    @endif
</section>

<a href="{{ route('projects.index', ['organization' => $organizationId]) }}" class="mt-6 inline-block text-sky-700 underline-offset-2 hover:underline focus:outline-none focus:ring-2 focus:ring-sky-400 dark:text-sky-300">Volver a proyectos</a>
@endsection
