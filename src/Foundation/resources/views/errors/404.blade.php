@extends('master')
@section('title', '404: ' . ($message ?? __('This page could not be found.')))

@section('content')
    <div id="error-page">
        <p id="error-code">404</p>
        <span id="error-separator"></span>
        <p>{{ $message ?? __('This page could not be found.') }}</p>
    </div>
@stop
