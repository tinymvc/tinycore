@extends('master')
@section('title', 'Server Error')

@section('content')
    <div id="error-page">
        <p id="error-code">{{ $code ?? 500 }}</p>
        <span id="error-separator"></span>
        <p>{{ $message ?? __('Internal Server Error') }}</p>
    </div>
@stop
