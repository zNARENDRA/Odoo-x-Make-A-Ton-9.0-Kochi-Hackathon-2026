<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>{{ config('app.name') }}</title>@vite(['resources/css/app.css', 'resources/js/app.js']) @livewireStyles</head>
<body class="min-h-screen bg-[#f6f8fb]" x-data="{ menuOpen: false }">
    <aside class="no-print fixed inset-y-0 left-0 z-40 hidden w-60 flex-col border-r border-slate-800 bg-slate-950 text-white lg:flex">
        <a href="{{ route('dashboard') }}" class="px-7 pt-8 pb-10" wire:navigate><x-brand class="text-xl" /></a>
        <p class="eyebrow px-7 pb-4 !text-slate-600">Workspace</p>
        <nav class="flex flex-col gap-1 px-4">
            @foreach (['dashboard' => ['Overview','◫'], 'subscriptions' => ['Subscriptions','↻'], 'invoices' => ['Invoices','▤'], 'customers' => ['Customers','♙'], 'catalog' => ['Products & plans','◇'], 'reports' => ['Reports','▥']] as $route => [$label, $icon])
                @if (!in_array($route, ['customers','catalog','reports']) || auth()->user()->isTeamMember())
                <a href="{{ route($route) }}" wire:navigate @class(['flex items-center gap-3 rounded-lg px-3 py-3 text-sm font-medium transition-colors','bg-blue-600 text-white shadow-sm' => request()->routeIs($route),'text-slate-400 hover:bg-white/5 hover:text-white' => !request()->routeIs($route)])><span class="w-5 text-center text-lg" aria-hidden="true">{{ $icon }}</span>{{ $label }}</a>
                @endif
            @endforeach
        </nav>
        <div class="mt-auto p-4"><div class="rounded-xl border border-slate-800 bg-slate-900/70 p-4"><p class="text-xs font-semibold text-blue-400">A smoother billing cycle</p><p class="mt-2 text-xs leading-5 text-slate-400">Review draft invoices, confirm totals, and record payments as they arrive.</p></div>
        <div class="mt-5 flex items-center gap-3 px-2"><div class="flex size-9 shrink-0 items-center justify-center rounded-full bg-slate-800 text-sm font-medium text-blue-200">{{ mb_substr(auth()->user()->name,0,1) }}</div><div class="min-w-0"><p class="truncate text-sm font-medium">{{ auth()->user()->name }}</p><p class="mt-0.5 text-xs capitalize text-slate-500">{{ auth()->user()->role }} workspace</p></div></div>
        <form method="POST" action="{{ route('logout') }}" class="mt-4 px-2">@csrf<button class="text-xs text-slate-500 hover:text-white">Sign out →</button></form></div>
    </aside>
    <div class="lg:pl-60">
        <header class="no-print flex h-20 items-center justify-between border-b border-slate-200 bg-white px-6 lg:px-9">
            <div class="flex items-center gap-3"><button class="rounded-lg border border-slate-200 p-2 lg:hidden" @click="menuOpen = !menuOpen" :aria-expanded="menuOpen" aria-label="Toggle navigation">☰</button><span class="text-sm text-slate-400">Workspace <span class="px-3 text-slate-300">/</span><span class="font-medium text-slate-700">{{ ucfirst(request()->route()->getName()) }}</span></span></div>
            <div class="flex items-center gap-4"><span class="hidden items-center gap-2 rounded-full border border-slate-200 px-3 py-1.5 text-xs text-slate-500 sm:flex"><span class="size-1.5 rounded-full bg-emerald-500"></span>Live workspace</span><span class="text-sm text-slate-500">{{ today()->format('d M Y') }}</span></div>
        </header>
        <nav x-cloak x-show="menuOpen" class="no-print border-b border-slate-200 bg-white p-5 lg:hidden">
            <div class="flex flex-wrap gap-4">@foreach(['dashboard','subscriptions','invoices','customers','catalog','reports'] as $route) @if(!in_array($route,['customers','catalog','reports']) || auth()->user()->isTeamMember()) <a class="text-sm font-medium text-slate-600" href="{{ route($route) }}" wire:navigate>{{ ucfirst($route) }}</a>@endif @endforeach</div>
            <form method="POST" action="{{ route('logout') }}" class="mt-4">@csrf<button class="text-sm text-slate-500">Sign out</button></form>
        </nav>
        <main class="mx-auto max-w-[1500px] px-5 py-8 lg:px-9 lg:py-9">{{ $slot }}</main>
        <footer class="no-print flex justify-between px-6 py-6 text-xs text-slate-400 lg:px-9"><span>Brightloop · Subscription management</span><span>{{ config('billing.currency') }} · Invoice totals include applicable tax</span></footer>
    </div>
    <style>[x-cloak]{display:none!important}</style>
    @livewireScripts
</body></html>

