@extends('layouts.app', ['title' => 'Organizaciones'])

@section('content')
<h1 class="mb-6 text-2xl font-semibold">Tus organizaciones</h1>

<section class="mb-8 rounded-xl border border-slate-200 bg-white shadow-sm dark:border-slate-700 dark:bg-slate-800">
    @forelse ($organizations as $organization)
        <form method="POST" action="{{ route('organizations.switch') }}" class="flex items-center justify-between border-b border-slate-100 px-5 py-4 last:border-0 dark:border-slate-700">
            @csrf
            <input type="hidden" name="organization" value="{{ $organization->id->value }}">
            <span class="font-medium">{{ $organization->name }}</span>
            <button type="submit" class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-sky-400 dark:border-slate-600 dark:hover:bg-slate-700">Abrir</button>
        </form>
    @empty
        <p class="px-5 py-4 text-slate-500">Aún no perteneces a ninguna organización.</p>
    @endforelse
</section>

<section class="max-w-md rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-800">
    <h2 class="mb-4 text-lg font-semibold">Crear organización</h2>
    <form method="POST" action="{{ route('organizations.store') }}" class="space-y-3">
        @csrf
        <label for="name" class="block text-sm font-medium">Nombre</label>
        <input id="name" name="name" type="text" maxlength="120" required
               class="w-full rounded-lg border border-slate-300 px-3 py-2 focus:border-sky-500 focus:outline-none focus:ring-2 focus:ring-sky-200 dark:border-slate-600 dark:bg-slate-900">
        <button type="submit" class="rounded-lg bg-slate-900 px-4 py-2 font-medium text-white hover:bg-slate-800 focus:outline-none focus:ring-2 focus:ring-sky-400">Crear</button>
    </form>
</section>
@endsection
