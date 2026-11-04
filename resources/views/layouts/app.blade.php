{{-- Provisional shell until 11-design-system: navy sidebar, light cards, soft borders (TailAdmin reference). --}}
<!DOCTYPE html>
<html lang="es" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'Orbixa' }} · Orbixa</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full bg-slate-50 text-slate-800 dark:bg-slate-900 dark:text-slate-100">
<a href="#contenido" class="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:rounded focus:bg-white focus:px-3 focus:py-2 focus:text-slate-900">Saltar al contenido</a>
<div class="min-h-full md:flex">
    <aside class="bg-slate-900 text-slate-100 md:w-64 md:min-h-screen" aria-label="Navegación principal">
        <div class="px-6 py-5 text-lg font-semibold tracking-tight">Orbixa</div>
        @auth
            <nav class="px-3 pb-4 space-y-1 text-sm">
                <a href="{{ route('organizations.index') }}" class="block rounded-md px-3 py-2 hover:bg-slate-800 focus:outline-none focus:ring-2 focus:ring-sky-400">Organizaciones</a>
                @isset($organizationId)
                    <a href="{{ route('organizations.show', ['organization' => $organizationId]) }}" class="block rounded-md px-3 py-2 hover:bg-slate-800 focus:outline-none focus:ring-2 focus:ring-sky-400">Inicio</a>
                    <a href="{{ route('organizations.members', ['organization' => $organizationId]) }}" class="block rounded-md px-3 py-2 hover:bg-slate-800 focus:outline-none focus:ring-2 focus:ring-sky-400">Miembros</a>
                @endisset
                <form method="POST" action="{{ route('logout') }}" class="pt-2">
                    @csrf
                    <button type="submit" class="w-full rounded-md px-3 py-2 text-left hover:bg-slate-800 focus:outline-none focus:ring-2 focus:ring-sky-400">Cerrar sesión</button>
                </form>
            </nav>
        @endauth
    </aside>
    <main id="contenido" class="flex-1 p-4 md:p-8">
        @if (session('status'))
            <div role="status" class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-emerald-800">{{ session('status') }}</div>
        @endif
        @if ($errors->any())
            <div role="alert" class="mb-4 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-rose-800">
                @foreach ($errors->all() as $error)<p>{{ $error }}</p>@endforeach
            </div>
        @endif
        @yield('content')
    </main>
</div>
</body>
</html>
