@extends('master')
@section('title', 'Server Error')

@section('content')
    <div id="error-page">
        <p id="error-code">{{ $code ?? \Spark\Http\Response::STATUS_INTERNAL_SERVER_ERROR }}</p>
        <span id="error-separator"></span>
        <p>{{ $message ?? __('Internal Server Error') }}</p>
    </div>
@stop
