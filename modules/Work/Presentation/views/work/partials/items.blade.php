<div class="mb-4 overflow-x-auto rounded-xl border border-slate-200 bg-white shadow-sm dark:border-slate-700 dark:bg-slate-800">
    <table class="min-w-full text-sm">
        <caption class="sr-only">Elementos de trabajo</caption>
        <thead class="bg-slate-50 text-left text-slate-600 dark:bg-slate-900 dark:text-slate-300">
            <tr><th scope="col" class="px-5 py-3">Clave</th><th scope="col" class="px-5 py-3">Título</th><th scope="col" class="px-5 py-3">Tipo</th><th scope="col" class="px-5 py-3">Estado</th><th scope="col" class="px-5 py-3">Prioridad</th></tr>
        </thead>
        <tbody>
        @forelse ($items as $item)
            <tr class="border-t border-slate-100 dark:border-slate-700">
                <td class="px-5 py-3 font-mono"><a href="{{ route('work.show', ['organization' => $organizationId, 'item' => $item['id']]) }}" class="text-sky-700 underline-offset-2 hover:underline focus:outline-none focus:ring-2 focus:ring-sky-400 dark:text-sky-300">{{ $item['key'] }}</a></td>
                <td class="px-5 py-3">{{ $item['title'] }}</td>
                <td class="px-5 py-3">{{ $item['type'] }}</td>
                <td class="px-5 py-3">{{ $statusLabels[$item['status']] ?? $item['status'] }}</td>
                <td class="px-5 py-3">{{ $item['priority'] }}</td>
            </tr>
        @empty
            <tr><td colspan="5" class="px-5 py-6 text-center text-slate-500">{{ $empty }}</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
