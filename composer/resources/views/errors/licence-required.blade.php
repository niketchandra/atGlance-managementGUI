<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Licence required - {{ $brandName ?? 'AtGlance' }}</title>
    <style>
        body { margin: 0; font-family: system-ui, -apple-system, "Segoe UI", sans-serif; background: #f1f5f9; color: #0f172a; }
        .box { max-width: 560px; margin: 80px auto; padding: 32px; background: #fff; border-radius: 16px; text-align: center; box-shadow: 0 10px 30px rgba(15, 23, 42, .08); }
        h1 { font-size: 20px; margin: 0 0 8px; }
        p { font-size: 14px; color: #475569; line-height: 1.6; }
        button { margin-top: 16px; padding: 10px 18px; border: 0; border-radius: 10px; background: #000; color: #fff; font-weight: 600; cursor: pointer; }
    </style>
</head>
<body>
    <div class="box">
        <h1>A licence is required</h1>
        <p>This Management Console has no valid licence yet, so it cannot be used. Ask your super admin to add the licence key or licence file in Admin Settings &rsaquo; Licence.</p>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit">Sign out</button>
        </form>
    </div>
</body>
</html>
