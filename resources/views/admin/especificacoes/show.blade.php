@extends('layouts.app')

@section('title', $titulo . ' | Lead Certo')

@section('content')
<div class="max-w-3xl mx-auto pb-16">

    <a href="{{ route('admin.especificacoes') }}" class="text-xs text-gray-400 hover:text-gray-600">&larr; Especificações Técnicas</a>

    <div class="mt-2 mb-6">
        <span class="text-xs font-semibold text-gray-500 bg-gray-100 px-2 py-0.5 rounded-full">Admin</span>
        <p class="text-xs text-gray-400 font-mono mt-1">{{ $arquivo }}</p>
    </div>

    <div class="md-content bg-white border border-gray-200 rounded-xl p-6 md:p-8">
        {!! $html !!}
    </div>

</div>

<style>
    .md-content h1 { font-size: 1.375rem; font-weight: 700; color: #1f2937; margin: 0 0 0.75rem; }
    .md-content h2 { font-size: 1.125rem; font-weight: 700; color: #1f2937; margin: 1.75rem 0 0.75rem; padding-top: 0.75rem; border-top: 1px solid #f3f4f6; }
    .md-content h2:first-child { margin-top: 0; padding-top: 0; border-top: none; }
    .md-content h3 { font-size: 0.95rem; font-weight: 700; color: #374151; margin: 1.25rem 0 0.5rem; }
    .md-content p { font-size: 0.875rem; color: #374151; line-height: 1.65; margin: 0 0 0.75rem; }
    .md-content ul, .md-content ol { font-size: 0.875rem; color: #374151; line-height: 1.65; margin: 0 0 0.75rem; padding-left: 1.25rem; }
    .md-content ul { list-style: disc; }
    .md-content ol { list-style: decimal; }
    .md-content li { margin-bottom: 0.25rem; }
    .md-content li > ul, .md-content li > ol { margin-top: 0.25rem; margin-bottom: 0; }
    .md-content strong { color: #111827; font-weight: 600; }
    .md-content code { font-family: ui-monospace, monospace; font-size: 0.8rem; background: #f3f4f6; color: #16a34a; padding: 0.1rem 0.35rem; border-radius: 0.25rem; }
    .md-content pre { background: #1f2937; color: #e5e7eb; padding: 0.9rem; border-radius: 0.5rem; overflow-x: auto; margin: 0 0 0.75rem; }
    .md-content pre code { background: none; color: inherit; padding: 0; font-size: 0.8rem; }
    .md-content blockquote { border-left: 3px solid #d1d5db; padding-left: 0.75rem; color: #6b7280; font-style: italic; margin: 0 0 0.75rem; }
    .md-content hr { border: none; border-top: 1px solid #e5e7eb; margin: 1.5rem 0; }
    .md-content table { width: 100%; border-collapse: collapse; font-size: 0.8rem; margin: 0 0 0.75rem; display: block; overflow-x: auto; }
    .md-content th, .md-content td { border: 1px solid #e5e7eb; padding: 0.4rem 0.6rem; text-align: left; }
    .md-content th { background: #f9fafb; font-weight: 600; }
</style>
@endsection
