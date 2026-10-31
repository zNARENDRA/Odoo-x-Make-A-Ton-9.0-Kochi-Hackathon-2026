@props(['title' => 'Welcome'])
<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>{{ $title }} · {{ config('app.name') }}</title>@vite(['resources/css/app.css','resources/js/app.js']) @fluxAppearance</head>
<body class="min-h-screen bg-slate-50">
    <div class="grid min-h-screen lg:grid-cols-2">
        <aside class="hidden flex-col justify-between bg-slate-950 p-14 text-white lg:flex">
            <a href="{{ route('home') }}"><x-brand class="text-2xl" /></a>
            <div class="max-w-lg"><p class="eyebrow !text-blue-400">ONE WORKSPACE. EVERY BILLING CYCLE.</p><h1 class="mt-5 text-5xl font-semibold leading-tight tracking-tight">Recurring business.<br><span class="text-blue-400">Clearer at every step.</span></h1><p class="mt-6 text-lg leading-relaxed text-slate-400">Bring your subscriptions, invoices and payments together. Keep your team in sync and your customers in the loop.</p><div class="mt-10 grid grid-cols-3 gap-5 border-t border-white/10 pt-7 text-sm text-slate-300"><span>01 · Subscribe</span><span>02 · Invoice</span><span>03 · Reconcile</span></div></div>
            <p class="text-xs text-slate-500">Brightloop Software · Subscription management</p>
        </aside>
        <main class="flex items-center justify-center bg-slate-50 px-6 py-14 text-slate-900 dark:bg-slate-900 dark:text-slate-100 [&_input::placeholder]:text-slate-600 dark:[&_input::placeholder]:text-slate-400">
            <div class="w-full max-w-sm">
                <a href="{{ route('home') }}" class="mb-10 block lg:hidden"><x-brand class="text-2xl" /></a>
                <div wire:transition.navigate="auth-form">{{ $slot }}</div>
                <p class="mt-8 text-center text-xs text-slate-600 dark:text-slate-400">Your account. Your subscriptions. One place.</p>
            </div>
        </main>
    </div>
    @fluxScripts
</body></html>
