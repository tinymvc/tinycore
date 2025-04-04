<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $title ?? 'Unknown Error' ?></title>

    <style>
        <?= file_get_contents(__DIR__ . '/../css/app.css') ?>
    </style>
</head>

<body>

    <?= $content ?? 'Unknown' ?>

</body>

</html>