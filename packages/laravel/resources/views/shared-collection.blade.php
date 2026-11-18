<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>{{ $share->title }}</title>
    <style nonce="{{ $nonce }}">
        *{box-sizing:border-box}body{margin:0;background:#fafafa;color:#202124;font:16px/1.5 system-ui,sans-serif}main{max-width:1120px;margin:auto;padding:32px 24px}header{display:flex;gap:16px;align-items:center;justify-content:space-between;flex-wrap:wrap}h1{font-size:28px;margin:0;overflow-wrap:anywhere}h2{font-size:16px;margin:12px 0;overflow-wrap:anywhere}.muted{color:#667085;font-size:14px}.actions{display:flex;flex-wrap:wrap;align-items:center;gap:12px;margin:20px 0}.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(min(220px,100%),1fr));gap:20px;margin:24px 0}article,.panel{background:white;border:1px solid #e4e4e7;border-radius:16px;padding:20px}article img{width:100%;height:180px;object-fit:contain}a{color:#2551ba;text-underline-offset:3px}input,button{font:inherit;max-width:100%;padding:10px 14px}input{border:1px solid #aaa;border-radius:8px}button{border:0;border-radius:10px;background:#202124;color:#fff;cursor:pointer}button:hover{background:#3e3e42}a:focus-visible,input:focus-visible,button:focus-visible{outline:3px solid #2551ba;outline-offset:3px}.status{padding:12px 16px;border-radius:10px;margin:20px 0;background:#eaf6ee;color:#15552d}.error{background:#fff0f0;color:#932326}.lock{max-width:480px;margin:64px auto}.lock label{display:block}.lock input{display:block;width:100%;margin:8px 0 16px}.upload{display:flex;flex-wrap:wrap;align-items:center;gap:12px}.upload label{flex:1;min-width:0}.upload input{display:block;width:100%;margin-top:6px}.pager{display:flex;gap:24px;margin:24px 0}.empty{padding:32px;text-align:center}.file-links{display:flex;flex-wrap:wrap;gap:16px}.folder{display:block;padding:24px 0;font-weight:600}@media(max-width:480px){main{padding:24px 16px}h1{font-size:24px}.lock{margin:32px auto}}
    </style>
</head>
<body>
<main>
    <header><h1>{{ $share->title }}</h1><span class="muted">Shared collection</span></header>
    @if (session('media-studio-share-success'))
        <p class="status" role="status">{{ session('media-studio-share-success') }}</p>
    @endif
    @if (session('media-studio-share-error') || $errors->any())
        <div class="status error" role="alert">
            @if (session('media-studio-share-error'))<p>{{ session('media-studio-share-error') }}</p>@endif
            @foreach ($errors->all() as $error)<p>{{ $error }}</p>@endforeach
        </div>
    @endif
    @if ($locked)
        <section class="panel lock">
            <p>This collection is password protected.</p>
            <form method="post" action="{{ route('media-studio.shared.unlock', ['token' => $token]) }}">
                @csrf
                <label>Password<input type="password" name="password" required maxlength="128" autocomplete="current-password"></label>
                <button type="submit">Open collection</button>
            </form>
        </section>
    @else
        <nav class="actions" aria-label="Collection navigation"><a href="{{ $collectionUrl }}">Collection</a></nav>
        @if ($share->allow_upload)
            <form class="panel upload" method="post" enctype="multipart/form-data" action="{{ route('media-studio.shared.upload', ['token' => $token]) }}">
                @csrf
                <input type="hidden" name="uploadKey" value="{{ (string) \Illuminate\Support\Str::ulid() }}">
                @if ($parent)<input type="hidden" name="parent" value="{{ $parent }}">@endif
                <label>Choose a file<input type="file" name="file" required></label>
                <button type="submit">Upload file</button>
            </form>
        @endif
        @if ($share->allow_download && $share->allow_archive)
            <div class="actions">
                <a href="{{ route('media-studio.shared.archive', ['token' => $token]) }}">Download collection</a>
                @if ($parent)<a href="{{ route('media-studio.shared.archive', ['token' => $token, 'parent' => $parent]) }}">Download this folder</a>@endif
                @if (config('media-studio.downloads.background_enabled', false))
                    <form method="post" action="{{ route('media-studio.shared.archive-create', ['token' => $token]) }}">
                        @csrf
                        @if ($parent)<input type="hidden" name="parent" value="{{ $parent }}">@endif
                        <button type="submit">Prepare {{ $parent ? 'folder' : 'collection' }} archive</button>
                    </form>
                @endif
            </div>
        @endif
        <div class="grid">
            @foreach ($page['items'] as $item)
                <article>
                    @if ($item['kind'] === 'folder')
                        <a class="folder" href="{{ $collectionUrl.'?'.http_build_query(['parent' => $item['id']]) }}">Folder · {{ $item['name'] }}</a>
                    @else
                        @if ($item['thumbnailUrl'])
                            @if ($item['previewUrl'])<a href="{{ $item['previewUrl'] }}">@endif
                            <img loading="lazy" decoding="async" width="220" height="180" src="{{ $item['thumbnailUrl'] }}" alt="">
                            @if ($item['previewUrl'])</a>@endif
                        @endif
                        <h2>{{ $item['name'] }}</h2>
                        <div class="file-links">
                            @if ($item['previewUrl'])<a href="{{ $item['previewUrl'] }}">Open preview</a>@endif
                            @if ($item['downloadUrl'])<a href="{{ $item['downloadUrl'] }}" download>Download</a>@endif
                        </div>
                    @endif
                    @if ($mayCopy && $copyWorkspaces !== [] && $item['kind'] === 'file' && $item['editable'] && $item['downloadUrl'])
                        <details><summary>Copy to Studio</summary>
                            <form method="post" action="{{ route('media-studio.shared.copy', ['token' => $token, 'entry' => $item['id']]) }}">
                                @csrf
                                <label>Destination workspace <select name="workspace" required>
                                    @foreach ($copyWorkspaces as $destination)<option value="{{ $destination['id'] }}">{{ $destination['name'] }}</option>@endforeach
                                </select></label>
                                <button type="submit">Copy file</button>
                            </form>
                        </details>
                    @endif
                    @if ($mayRename && $item['editable'])
                        <details><summary>Rename</summary>
                            <form method="post" action="{{ route('media-studio.shared.rename', ['token' => $token, 'entry' => $item['id']]) }}">
                                @csrf
                                <input type="hidden" name="revision" value="{{ $item['revision'] }}">
                                <input aria-label="Name" name="name" required maxlength="191" value="{{ $item['name'] }}">
                                <button type="submit">Save name</button>
                            </form>
                        </details>
                    @endif
                </article>
            @endforeach
        </div>
        @if ($share->recipient_permission !== null)
            <section class="panel"><h2>Shared collection comments</h2>
                @foreach ($guestComments as $comment)<p>{{ $comment->body }}</p>@endforeach
                @if ($mayComment)
                    <form method="post" action="{{ route('media-studio.shared.comment', ['token' => $token]) }}">
                        @csrf
                        <label>Comment <input name="body" required maxlength="2000"></label>
                        <button type="submit">Post comment</button>
                    </form>
                @endif
            </section>
        @endif
        @if ($page['items'] === [])<p class="muted empty">No available files on this page.</p>@endif
        <nav class="pager" aria-label="Collection pages">
            @foreach (['previousCursor' => 'Previous', 'nextCursor' => 'Next'] as $key => $label)
                @if ($page[$key])<a href="{{ $collectionUrl.'?'.http_build_query(['parent' => $parent, 'cursor' => $page[$key]]) }}">{{ $label }}</a>@endif
            @endforeach
        </nav>
    @endif
</main>
@if ($engagementTicket ?? null)
<script nonce="{{ $nonce }}">
    (() => {
        const endpoint = {{ Illuminate\Support\Js::from(route('media-studio.shared.engagement', ['token' => $token])) }};
        const ticket = {{ Illuminate\Support\Js::from($engagementTicket) }};
        const csrf = {{ Illuminate\Support\Js::from(csrf_token()) }};
        let visibleSince = document.hidden ? null : performance.now();
        let visibleMs = 0;
        document.addEventListener('visibilitychange', () => {
            if (visibleSince !== null) visibleMs += performance.now() - visibleSince;
            visibleSince = document.hidden ? null : performance.now();
        });
        addEventListener('pagehide', () => {
            const seconds = Math.min(1800, Math.floor((visibleMs + (visibleSince === null ? 0 : performance.now() - visibleSince)) / 1000));
            if (seconds > 0) navigator.sendBeacon(endpoint, new URLSearchParams({_token: csrf, ticket, seconds: String(seconds)}));
        }, {once: true});
    })();
</script>
@endif
</body>
</html>
