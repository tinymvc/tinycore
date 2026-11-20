<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Internal Server Error</title>
</head>

<body style="background-color:#000000;color:#ededed;">
    <div style="font:16px/1.5 system-ui,sans-serif;max-width:480px;margin:18vh auto;padding:0 24px;text-align:center;">
        <div style="font-size:80px;font-weight:700;letter-spacing:-.04em;color:#29292a;"><?= e($code ?? '500') ?></div>
        <h1 style="margin:0 0 8px;font-size:20px;font-weight:600;">Internal Server Error</h1>
        <p style="margin:0;font-size:14px;color:#7f8289;">
            <?= e($message ?? 'Something went wrong.') ?>
        </p>
    </div>
</body>

</html>