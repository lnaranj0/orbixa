@extends('layouts.app', ['title' => 'Aceptar invitación'])

@section('content')
<section class="mx-auto mt-10 max-w-md rounded-xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-700 dark:bg-slate-800">
    <h1 class="mb-4 text-xl font-semibold">Aceptar invitación</h1>
    <p class="mb-6 text-slate-600 dark:text-slate-300">Al aceptar te unirás a la organización con el rol indicado en la invitación.</p>
    <form method="POST" action="{{ route('invitations.accept', ['organization' => $organizationId]) }}">
        @csrf
        <button type="submit" class="w-full rounded-lg bg-slate-900 px-4 py-2 font-medium text-white hover:bg-slate-800 focus:outline-none focus:ring-2 focus:ring-sky-400">Aceptar</button>
    </form>
</section>
@endsection
