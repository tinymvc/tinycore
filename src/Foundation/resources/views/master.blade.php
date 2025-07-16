<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title')</title>

    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font: 1rem system-ui, sans-serif;
            background-color: #f1f1f1;
        }

        #error-page {
            color: #666;
            font-size: 1.35rem;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 100%;
            min-height: 100vh;
            gap: 0.8rem;
        }

        #error-code {
            font-weight: 500;
            color: #555;
        }

        #error-separator {
            width: 2px;
            height: 2rem;
            background-color: #777;
        }
    </style>

    @yield('head')
</head>

<body>

    @yield('content')

</body>

</html>
