@extends('app')

@section('title', $pageTitle . ' - ' . $brandName)

@php
    $markdown = fn (string $text) => \App\Support\SiteProfile::markdown($text);
    $support = $profile->support();
@endphp

@section('public-content')
    @include('partials.public-page-body')
@endsection

@section('dashboard-content')
    <div style="padding: 32px;">
        @include('partials.public-page-body')
    </div>
@endsection
