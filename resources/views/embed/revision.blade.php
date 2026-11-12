@extends('embed.layout')

@section('title', $topic->title)

@section('content')
<div class="p-6 lg:p-10">
    <div class="rounded-2xl border border-amber-200 bg-amber-50 p-6 text-amber-900">
        <p class="text-xs font-semibold uppercase tracking-wide">In Überarbeitung</p>
        <h1 class="mt-2 text-2xl font-bold tracking-tight">{{ $topic->title }}</h1>
        <p class="mt-2 text-sm">Dieser Kurs wird gerade überarbeitet und ist bald wieder verfügbar.</p>
    </div>
</div>
@endsection
