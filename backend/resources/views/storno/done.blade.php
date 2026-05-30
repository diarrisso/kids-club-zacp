<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Termin storniert — {{ $cabinetName }}</title>
    <style>
        body { font-family: system-ui, -apple-system, sans-serif; background: #f3f4f6; margin: 0; padding: 2rem 1rem; color: #111827; }
        .card { max-width: 32rem; margin: 0 auto; background: #fff; border-radius: 0.75rem; padding: 2rem; box-shadow: 0 1px 3px rgba(0,0,0,.1); text-align: center; }
        h1 { font-size: 1.25rem; margin: 0 0 1rem; }
    </style>
</head>
<body>
    <div class="card">
        <h1>Ihr Termin wurde storniert</h1>
        <p>Vielen Dank. Falls Sie einen neuen Termin benötigen, buchen Sie jederzeit online bei <strong>{{ $cabinetName }}</strong>.</p>
    </div>
</body>
</html>
