@extends('layouts.app', ['title' => $item->key])

@section('content')
<p class="mb-1 font-mono text-sm text-slate-500">{{ $item->key }} · {{ $item->type }} · solo lectura</p>
<h1 class="mb-6 text-2xl font-semibold">{{ $item->title }}</h1>

<section class="max-w-3xl rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-800">
    <dl class="grid grid-cols-2 gap-4 text-sm sm:grid-cols-4">
        <div><dt class="text-slate-500">Estado</dt><dd class="font-medium">{{ $item->status }}</dd></div>
        <div><dt class="text-slate-500">Prioridad</dt><dd class="font-medium">{{ $item->priority }}</dd></div>
        <div><dt class="text-slate-500">Vence</dt><dd class="font-medium">{{ $item->dueOn ?? '—' }}</dd></div>
        <div><dt class="text-slate-500">Actualizado</dt><dd class="font-medium">{{ \Illuminate\Support\Carbon::parse($item->updatedAt)->format('Y-m-d') }}</dd></div>
    </dl>
    @if ($item->description)
        <p class="mt-4 whitespace-pre-line text-slate-700 dark:text-slate-300">{{ $item->description }}</p>
    @endif
</section>

<a href="{{ route('monitoring.panel', ['organization' => $organizationId]) }}" class="mt-6 inline-block text-sky-700 underline-offset-2 hover:underline focus:outline-none focus:ring-2 focus:ring-sky-400 dark:text-sky-300">Volver al monitoreo</a>
@endsection
