<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;
use League\CommonMark\CommonMarkConverter;
use Symfony\Component\HttpFoundation\Response;

class EspecificacoesController extends Controller
{
    private function diretorio(): string
    {
        return base_path('docs/superpowers/specs');
    }

    public function index(): View
    {
        $arquivos = collect(glob($this->diretorio() . '/*.md'))
            ->map(fn (string $caminho) => [
                'arquivo' => basename($caminho),
                'titulo'  => $this->extrairTitulo($caminho),
            ])
            ->sortByDesc('arquivo')
            ->values();

        return view('admin.especificacoes.index', ['arquivos' => $arquivos]);
    }

    public function show(Request $request, string $arquivo): View|Response
    {
        $nomeSeguro = basename($arquivo);
        $caminho    = $this->diretorio() . '/' . $nomeSeguro;

        if (! str_ends_with($nomeSeguro, '.md') || ! is_file($caminho)) {
            abort(404);
        }

        $converter = new CommonMarkConverter();
        $html      = $converter->convert(file_get_contents($caminho))->getContent();

        return view('admin.especificacoes.show', [
            'arquivo' => $nomeSeguro,
            'titulo'  => $this->extrairTitulo($caminho),
            'html'    => $html,
        ]);
    }

    private function extrairTitulo(string $caminho): string
    {
        $linhas = file($caminho, FILE_IGNORE_NEW_LINES);
        foreach ($linhas as $linha) {
            if (str_starts_with(trim($linha), '# ')) {
                return trim(substr(trim($linha), 2));
            }
        }

        return basename($caminho, '.md');
    }
}
