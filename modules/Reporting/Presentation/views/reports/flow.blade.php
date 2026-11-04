@extends('layouts.app', ['title' => 'Métricas de flujo'])

@section('content')
<h1 class="mb-2 text-2xl font-semibold">Métricas de flujo</h1>
<p class="mb-6 text-sm text-slate-500">Del {{ $period->from }} al {{ $period->to }}, solo sobre proyectos que puedes ver. Los cancelados no cuentan como entrega; no hay métricas por persona.</p>

<div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
    <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-700 dark:bg-slate-800">
        <p class="text-sm text-slate-500">Throughput</p>
        <p class="text-2xl font-semibold">{{ $metrics['throughput'] }}</p>
    </div>
    @foreach (['lead_time_days' => 'Lead time', 'cycle_time_days' => 'Cycle time', 'aging_days' => 'Aging'] as $key => $label)
        <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-700 dark:bg-slate-800">
            <p class="text-sm text-slate-500">{{ $label }} (días, mediana · p85)</p>
            <p class="text-2xl font-semibold">{{ $metrics[$key]['median'] ?? '—' }} · {{ $metrics[$key]['p85'] ?? '—' }}</p>
            <p class="text-xs text-slate-500">{{ $metrics[$key]['count'] }} items</p>
        </div>
    @endforeach
</div>
@endsection
