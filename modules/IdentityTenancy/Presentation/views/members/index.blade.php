@extends('layouts.app', ['title' => 'Miembros'])

@section('content')
<h1 class="mb-6 text-2xl font-semibold">Miembros de {{ $organizationName }}</h1>

@if (session('invitation_link'))
    <div role="status" class="mb-6 rounded-lg border border-sky-200 bg-sky-50 px-4 py-3 text-sky-900">
        <p class="font-medium">Invitación creada. Este enlace se muestra una sola vez:</p>
        <code class="mt-2 block break-all text-sm">{{ session('invitation_link') }}</code>
    </div>
@endif

<div class="mb-8 overflow-x-auto rounded-xl border border-slate-200 bg-white shadow-sm dark:border-slate-700 dark:bg-slate-800">
    <table class="min-w-full text-sm">
        <caption class="sr-only">Miembros activos</caption>
        <thead class="bg-slate-50 text-left text-slate-600 dark:bg-slate-900 dark:text-slate-300">
            <tr><th scope="col" class="px-5 py-3">Nombre</th><th scope="col" class="px-5 py-3">Correo</th><th scope="col" class="px-5 py-3">Desde</th>@if ($canManage)<th scope="col" class="px-5 py-3"><span class="sr-only">Acciones</span></th>@endif</tr>
        </thead>
        <tbody>
        @foreach ($members as $member)
            <tr class="border-t border-slate-100 dark:border-slate-700">
                <td class="px-5 py-3">{{ $member['name'] }}</td>
                <td class="px-5 py-3">{{ $member['email'] }}</td>
                <td class="px-5 py-3">{{ \Illuminate\Support\Carbon::parse($member['joined_at'])->format('Y-m-d') }}</td>
                @if ($canManage)
                    <td class="px-5 py-3 text-right">
                        <form method="POST" action="{{ route('organizations.members.revoke', ['organization' => $organizationId, 'membership' => $member['id']]) }}">
                            @csrf
                            <button type="submit" class="rounded-lg border border-rose-300 px-3 py-1 text-rose-700 hover:bg-rose-50 focus:outline-none focus:ring-2 focus:ring-rose-300">Revocar</button>
                        </form>
                    </td>
                @endif
            </tr>
        @endforeach
        </tbody>
    </table>
</div>

@if ($canInvite)
<section class="max-w-md rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-800">
    <h2 class="mb-4 text-lg font-semibold">Invitar</h2>
    <form method="POST" action="{{ route('organizations.members.invite', ['organization' => $organizationId]) }}" class="space-y-3">
        @csrf
        <label for="email" class="block text-sm font-medium">Correo electrónico</label>
        <input id="email" name="email" type="email" required maxlength="254" class="w-full rounded-lg border border-slate-300 px-3 py-2 focus:border-sky-500 focus:outline-none focus:ring-2 focus:ring-sky-200 dark:border-slate-600 dark:bg-slate-900">
        <label for="role" class="block text-sm font-medium">Rol</label>
        <select id="role" name="role" class="w-full rounded-lg border border-slate-300 px-3 py-2 focus:border-sky-500 focus:outline-none focus:ring-2 focus:ring-sky-200 dark:border-slate-600 dark:bg-slate-900">
            @foreach ($roles as $role)<option value="{{ $role }}">{{ $role }}</option>@endforeach
        </select>
        <button type="submit" class="rounded-lg bg-slate-900 px-4 py-2 font-medium text-white hover:bg-slate-800 focus:outline-none focus:ring-2 focus:ring-sky-400">Enviar invitación</button>
    </form>
</section>
@endif
@endsection
