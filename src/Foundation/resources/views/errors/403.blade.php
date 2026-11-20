@extends('master')
@section('title', '403: ' . ($message ?? __('Forbidden')))

@section('content')
    <div id="error-page">
        <p id="error-code">403</p>
        <span id="error-separator"></span>
        <p>{{ $message ?? __('Forbidden') }}</p>
    </div>
@stop
