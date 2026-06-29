@extends('master')
@section('title', 'Internal Server Error')

@section('content')
    <div id="error-page">
        <p id="error-code">500</p>
        <span id="error-separator"></span>
        <p>{{ $message ?? __('Internal Server Error') }}</p>
    </div>
@stop
