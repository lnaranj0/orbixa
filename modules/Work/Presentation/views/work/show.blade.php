@extends('layouts.app', ['title' => $detail['item']['key']])

@section('content')
@php($item = $detail['item'])
<p class="mb-1 font-mono text-sm text-slate-500">{{ $item['key'] }} · {{ $item['type'] }}</p>
<h1 class="mb-6 text-2xl font-semibold">{{ $item['title'] }}</h1>

<div class="grid gap-6 lg:grid-cols-3">
    <section class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm lg:col-span-2 dark:border-slate-700 dark:bg-slate-800">
        <dl class="grid grid-cols-2 gap-4 text-sm sm:grid-cols-4">
            <div><dt class="text-slate-500">Estado</dt><dd class="font-medium">{{ $statusLabels[$item['status']] ?? $item['status'] }}</dd></div>
            <div><dt class="text-slate-500">Prioridad</dt><dd class="font-medium">{{ $item['priority'] }}</dd></div>
            <div><dt class="text-slate-500">Estimación</dt><dd class="font-medium">{{ $item['estimate'] ?? '—' }}</dd></div>
            <div><dt class="text-slate-500">Vence</dt><dd class="font-medium">{{ $item['due_on'] ?? '—' }}</dd></div>
        </dl>
        @if ($item['description'])
            <p class="mt-4 whitespace-pre-line text-slate-700 dark:text-slate-300">{{ $item['description'] }}</p>
        @endif

        @if ($detail['labels'])
            <ul class="mt-4 flex flex-wrap gap-2" aria-label="Etiquetas">
                @foreach ($detail['labels'] as $label)<li class="rounded-full border border-slate-300 px-3 py-1 text-xs dark:border-slate-600">{{ $label['name'] }}</li>@endforeach
            </ul>
        @endif

        @if ($detail['dependencies']['blocked_by'])
            <h2 class="mt-6 text-sm font-semibold">Bloqueado por</h2>
            <ul class="mt-2 space-y-1 text-sm">
                @foreach ($detail['dependencies']['blocked_by'] as $blocker)
                    <li><a href="{{ route('work.show', ['organization' => $organizationId, 'item' => $blocker['id']]) }}" class="font-mono text-sky-700 underline-offset-2 hover:underline dark:text-sky-300">{{ $blocker['key'] }}</a> · {{ $statusLabels[$blocker['status']] ?? $blocker['status'] }}</li>
                @endforeach
            </ul>
        @endif
    </section>

    @if ($canTransition && $nextStatuses)
    <section class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-800">
        <h2 class="mb-4 text-lg font-semibold">Cambiar estado</h2>
        <form method="POST" action="{{ route('work.transition', ['organization' => $organizationId, 'item' => $item['id']]) }}" class="space-y-3">
            @csrf
            <input type="hidden" name="expected_version" value="{{ $item['version'] }}">
            <label for="to" class="block text-sm font-medium">Nuevo estado</label>
            <select id="to" name="to" class="w-full rounded-lg border border-slate-300 px-3 py-2 focus:border-sky-500 focus:outline-none focus:ring-2 focus:ring-sky-200 dark:border-slate-600 dark:bg-slate-900">
                @foreach ($nextStatuses as $next)<option value="{{ $next['id'] }}">{{ $next['name'] }}{{ $next['needsReason'] ? ' (requiere motivo)' : '' }}</option>@endforeach
            </select>
            <label for="reason" class="block text-sm font-medium">Motivo</label>
            <input id="reason" name="reason" maxlength="500" class="w-full rounded-lg border border-slate-300 px-3 py-2 focus:border-sky-500 focus:outline-none focus:ring-2 focus:ring-sky-200 dark:border-slate-600 dark:bg-slate-900">
            <button type="submit" class="rounded-lg bg-slate-900 px-4 py-2 font-medium text-white hover:bg-slate-800 focus:outline-none focus:ring-2 focus:ring-sky-400">Aplicar</button>
        </form>
    </section>
    @endif
</div>

<section class="mt-6 rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-800">
    <h2 class="mb-4 text-lg font-semibold">Actividad</h2>
    <ol class="space-y-2 text-sm">
        @foreach ($activity as $event)
            <li class="border-l-2 border-slate-200 pl-3 dark:border-slate-600">
                <span class="text-slate-500">{{ \Illuminate\Support\Carbon::parse($event['occurred_at'])->format('Y-m-d H:i') }}</span>
                · {{ $event['type'] }}
                @foreach ($event['changes'] as $field => $change)
                    @if (is_array($change) && array_key_exists('to', $change) && is_scalar($change['to'] ?? '') && is_scalar($change['from'] ?? ''))
                        <span class="text-slate-600 dark:text-slate-300">· {{ $field }}: {{ $change['from'] ?? '—' }} → {{ $change['to'] ?? '—' }}</span>
                    @endif
                @endforeach
            </li>
        @endforeach
    </ol>
</section>
@endsection
