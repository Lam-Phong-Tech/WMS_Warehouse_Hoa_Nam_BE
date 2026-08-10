<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ config('app.name', 'WMS_HoaNam') }}</title>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="bg-zinc-50 text-zinc-950 antialiased">
        <main class="min-h-screen px-4 py-6 sm:px-6 lg:px-8" data-wms-health-root>
            <section class="mx-auto flex max-w-5xl flex-col gap-6">
                <header class="flex flex-col gap-3 border-b border-zinc-200 pb-5 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <p class="text-sm font-medium text-emerald-700">HN-DEVOPS-001 / FE1</p>
                        <h1 class="mt-1 text-2xl font-semibold text-zinc-950 sm:text-3xl">WMS Hoa Nam Frontend Health</h1>
                    </div>
                    <button
                        type="button"
                        class="inline-flex h-10 items-center justify-center rounded-md bg-zinc-950 px-4 text-sm font-medium text-white transition hover:bg-zinc-800 disabled:cursor-not-allowed disabled:bg-zinc-300"
                        data-health-refresh
                    >
                        Refresh
                    </button>
                </header>

                <section class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    <div class="rounded-md border border-zinc-200 bg-white p-4">
                        <p class="text-xs font-medium uppercase text-zinc-500">Application</p>
                        <p class="mt-2 text-base font-semibold" data-env-app></p>
                    </div>
                    <div class="rounded-md border border-zinc-200 bg-white p-4">
                        <p class="text-xs font-medium uppercase text-zinc-500">Environment</p>
                        <p class="mt-2 text-base font-semibold" data-env-name></p>
                    </div>
                    <div class="rounded-md border border-zinc-200 bg-white p-4">
                        <p class="text-xs font-medium uppercase text-zinc-500">Build</p>
                        <p class="mt-2 break-all text-sm font-semibold"><span data-env-version></span> / <span data-env-sha></span></p>
                    </div>
                </section>

                <section class="rounded-md border border-zinc-200 bg-white p-4">
                    <div data-state="loading">
                        <p class="text-sm font-medium text-zinc-700">Checking backend health...</p>
                    </div>

                    <div data-state="success" hidden>
                        <p class="text-sm font-semibold text-emerald-700" data-health-message></p>
                    </div>

                    <div data-state="error" hidden>
                        <p class="text-sm font-semibold text-red-700" data-health-message></p>
                    </div>

                    <div data-state="empty" hidden>
                        <p class="text-sm font-medium text-zinc-700">No health payload returned.</p>
                    </div>

                    <div data-state="permission" hidden>
                        <p class="text-sm font-medium text-amber-700">RBAC is enforced by backend API middleware; this screen does not grant permissions.</p>
                    </div>

                    <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-2">
                        <div>
                            <dt class="font-medium text-zinc-500">API base URL</dt>
                            <dd class="mt-1 break-all" data-env-api></dd>
                        </div>
                        <div>
                            <dt class="font-medium text-zinc-500">Health endpoint</dt>
                            <dd class="mt-1 break-all" data-env-health></dd>
                        </div>
                        <div>
                            <dt class="font-medium text-zinc-500">HTTP status</dt>
                            <dd class="mt-1" data-health-status></dd>
                        </div>
                        <div>
                            <dt class="font-medium text-zinc-500">Latency</dt>
                            <dd class="mt-1" data-health-latency></dd>
                        </div>
                        <div>
                            <dt class="font-medium text-zinc-500">Checked at</dt>
                            <dd class="mt-1 break-all" data-health-checked-at></dd>
                        </div>
                        <div>
                            <dt class="font-medium text-zinc-500">Response body</dt>
                            <dd class="mt-1 break-all" data-health-body></dd>
                        </div>
                    </dl>
                </section>
            </section>
        </main>
    </body>
</html>
