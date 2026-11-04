@extends('layouts.app', ['title' => 'Mi trabajo'])

@section('content')
<h1 class="mb-6 text-2xl font-semibold">Mi trabajo</h1>

@include('work::work.partials.items', ['items' => $items, 'empty' => 'No tienes elementos asignados.'])

@if ($nextCursor)
    <a href="{{ route('work.mine', ['organization' => $organizationId, 'cursor' => $nextCursor]) }}" class="inline-block rounded-lg border border-slate-300 px-4 py-2 hover:bg-slate-100 focus:outline-none focus:ring-2 focus:ring-sky-400 dark:border-slate-600 dark:hover:bg-slate-800">Siguiente página</a>
@endif
@endsection
