@extends('layouts.app', ['title' => 'Concesiones de monitoreo'])

@section('content')
<h1 class="mb-2 text-2xl font-semibold">Concesiones de monitoreo</h1>
<p class="mb-6 text-sm text-slate-500">Concesiones vigentes. Se emiten mediante la API (<code>POST /monitoring/grants</code>) y cada cambio queda auditado.</p>

<div class="overflow-x-auto rounded-xl border border-slate-200 bg-white shadow-sm dark:border-slate-700 dark:bg-slate-800">
    <table class="min-w-full text-sm">
        <caption class="sr-only">Concesiones de monitoreo vigentes</caption>
        <thead class="bg-slate-50 text-left text-slate-600 dark:bg-slate-900 dark:text-slate-300">
            <tr><th scope="col" class="px-5 py-3">Visor</th><th scope="col" class="px-5 py-3">Alcance</th><th scope="col" class="px-5 py-3">Nivel</th><th scope="col" class="px-5 py-3">Vence</th><th scope="col" class="px-5 py-3">Motivo</th>@if ($canManage)<th scope="col" class="px-5 py-3"><span class="sr-only">Acciones</span></th>@endif</tr>
        </thead>
        <tbody>
        @forelse ($grants as $grant)
            <tr class="border-t border-slate-100 dark:border-slate-700">
                <td class="px-5 py-3 font-mono text-xs">{{ $grant['viewer_membership_id'] }}</td>
                <td class="px-5 py-3">{{ ['user' => 'Persona', 'team' => 'Equipo', 'organization' => 'Organización'][$grant['scope_type']] }}{{ $grant['include_descendants'] ? ' (con subequipos)' : '' }}</td>
                <td class="px-5 py-3">{{ $grant['visibility_level'] === 'restricted' ? 'Restringido' : 'Estándar' }}</td>
                <td class="px-5 py-3">{{ \Illuminate\Support\Carbon::parse($grant['expires_at'])->format('Y-m-d') }}</td>
                <td class="px-5 py-3">{{ $grant['reason'] }}</td>
                @if ($canManage)
                    <td class="px-5 py-3 text-right">
                        <form method="POST" action="{{ route('monitoring.grants.revoke', ['organization' => $organizationId, 'grant' => $grant['id']]) }}">
                            @csrf
                            <button type="submit" class="rounded-lg border border-rose-300 px-3 py-1 text-rose-700 hover:bg-rose-50 focus:outline-none focus:ring-2 focus:ring-rose-300">Revocar</button>
                        </form>
                    </td>
                @endif
            </tr>
        @empty
            <tr><td colspan="6" class="px-5 py-6 text-center text-slate-500">No hay concesiones vigentes.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@endsection
