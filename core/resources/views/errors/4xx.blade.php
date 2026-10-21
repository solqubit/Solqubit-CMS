@extends('errors.layout')
@php($copy = \App\Services\ThemeErrorPage::MESSAGES[isset($exception) && method_exists($exception, 'getStatusCode') ? $exception->getStatusCode() : 404] ?? ['title' => 'No pudimos completar la solicitud', 'message' => 'Revisa la dirección o vuelve a la portada.'])
@section('title', $copy['title'])
@section('message', $copy['message'])
