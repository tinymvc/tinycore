@extends('master')

@section('title', "$type: $message")

@section('content')
    <div class="tracer">
        <div class="error-box">
            <h1>{!! $type !!}: {!! $message !!}</h1>
            <p><span class="file">{!! $file !!}</span> at line <span class="line">{!! $line !!}</span>
            </p>
        </div>
        @if (isset($trace) && !empty($trace))
            <div class="trace-box">
                <h2>Stack Trace</h2>
                <pre>
@foreach ($trace as $index => $frame)
@php
    $file = $frame['file'] ?? '[internal function]';
    $line = $frame['line'] ?? '';
    $function = $frame['function'] ?? '';
    $class = $frame['class'] ?? '';
    $type = $frame['type'] ?? '';
    $args = isset($frame['args'])
        ? implode(', ', array_map(fn($arg) => is_object($arg) ? get_class($arg) : gettype($arg), $frame['args']))
        : '';

    echo "#{$index} {$file}({$line}): {$class}{$type}{$function}({$args})\n";
@endphp
@endforeach
</pre>
            </div>
        @endif
    </div>
@stop
