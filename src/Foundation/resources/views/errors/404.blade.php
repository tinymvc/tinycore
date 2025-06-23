@extends('master')
@section('title', 'Page Not found')

@section('content')
    <div id="error-page">
        <p id="error-code">404</p>
        <span id="error-separator"></span>
        <p>{{ $message ?? __('Not found') }}</p>
    </div>
@stop
