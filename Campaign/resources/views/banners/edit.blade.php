@extends('layouts.app')

@section('title', 'Edit banner')

@section('content')

    <div class="c-header">
        <h2>Banners</h2>
    </div>
    <div class="card">
        <div class="card-header">
            <h2>Edit banner <small>{{ $banner->name }}</small></h2>
            <div class="actions">
                <a href="{{ route('banners.show', $banner) }}" class="btn palette-Cyan bg waves-effect">
                    <i class="zmdi zmdi-palette-Cyan zmdi-eye"></i> Show
                </a>
            </div>
        </div>
    </div>

    {!! Form::model($banner, ['route' => ['banners.update', $banner], 'method' => 'PATCH']) !!}
    @include('banners._form')
    {!! Form::close() !!}

@endsection