<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'Server Error')</title>

    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            background-color: #000000;
            color: #ededed;
            min-height: 100vh;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }

        #error-page {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 100%;
            min-height: 100vh;
            text-align: center;
            padding: 24px;
        }

        #error-code {
            font-size: 24px;
            font-weight: 600;
            color: #ffffff;
            line-height: 48px;
            margin: 0 20px 0 0;
            padding: 0 23px 0 0;
            border-right: 1px solid rgba(255, 255, 255, 0.2);
            display: inline-block;
            vertical-align: top;
        }

        #error-separator {
            display: none;
        }

        #error-page p:last-child {
            font-size: 14px;
            font-weight: 400;
            line-height: 48px;
            color: #a1a1aa;
            margin: 0;
            display: inline-block;
        }
    </style>

    @yield('head')
</head>

<body>

    @yield('content')

</body>

</html>
