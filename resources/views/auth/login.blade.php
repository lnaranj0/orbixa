@extends('layouts.app', ['title' => 'Iniciar sesión'])

@section('content')
<section class="mx-auto mt-10 max-w-md rounded-xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-700 dark:bg-slate-800">
    <h1 class="mb-6 text-xl font-semibold">Iniciar sesión</h1>
    <form method="POST" action="{{ route('login') }}" class="space-y-4">
        @csrf
        <div>
            <label for="email" class="mb-1 block text-sm font-medium">Correo electrónico</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="username"
                   class="w-full rounded-lg border border-slate-300 px-3 py-2 focus:border-sky-500 focus:outline-none focus:ring-2 focus:ring-sky-200 dark:border-slate-600 dark:bg-slate-900">
        </div>
        <div>
            <label for="password" class="mb-1 block text-sm font-medium">Contraseña</label>
            <input id="password" name="password" type="password" required autocomplete="current-password"
                   class="w-full rounded-lg border border-slate-300 px-3 py-2 focus:border-sky-500 focus:outline-none focus:ring-2 focus:ring-sky-200 dark:border-slate-600 dark:bg-slate-900">
        </div>
        <button type="submit" class="w-full rounded-lg bg-slate-900 px-4 py-2 font-medium text-white hover:bg-slate-800 focus:outline-none focus:ring-2 focus:ring-sky-400">Entrar</button>
    </form>
</section>
@endsection
