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

        .tracer {
            padding: 20px;
            margin: auto;
        }

        .tracer .error-box {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
            padding: 20px;
        }

        .tracer .trace-box {
            padding: 10px;
            border: 1px solid #ccc;
            margin-top: 10px;
            margin-top: 20px;
        }

        .tracer .trace-box pre {
            padding: 5px;
            overflow: auto;
            line-height: 22px;
            margin: 0;
        }

        .tracer h1,
        .tracer h2 {
            font-size: 24px;
            margin: 0 0 10px;
            font-weight: 500;
        }

        .tracer h2 {
            font-size: 22px;
        }

        .tracer p {
            margin: 0 0 10px;
        }

        .tracer .file {
            font-weight: bold;
        }

        .tracer .line {
            font-weight: bold;
            color: #d9534f;
        }
    </style>
</head>

<body>

    @yield('content')

</body>

</html>
