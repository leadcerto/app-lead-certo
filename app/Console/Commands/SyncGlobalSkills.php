<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use App\Models\AgentSkill;

#[Signature('leadcerto:sync-skills')]
#[Description('Sincroniza as skills globais da pasta _global/skills para o banco de dados')]
class SyncGlobalSkills extends Command
{
    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info("Iniciando sincronização de skills globais...");
        
        $skillsPath = base_path('../../../_global/skills');
        
        if (!File::isDirectory($skillsPath)) {
            $this->error("Pasta de skills não encontrada: {$skillsPath}");
            return;
        }

        $directories = File::directories($skillsPath);
        $count = 0;

        foreach ($directories as $dir) {
            $folderName = basename($dir);
            $skillFile = $dir . '/SKILL.md';
            
            if (File::exists($skillFile)) {
                $content = File::get($skillFile);
                
                // Extrair frontmatter (YAML) rudimentar
                $nome = $folderName;
                $descricaoCurta = 'Agente ' . str_replace('-', ' ', $folderName);
                $titulo = ucwords(str_replace('-', ' ', $folderName));
                
                if (preg_match('/^---\n(.*?)\n---\n(.*)$/s', $content, $matches)) {
                    $frontmatter = $matches[1];
                    $body = $matches[2];
                    
                    if (preg_match('/name:\s*(.+)/', $frontmatter, $nameMatch)) {
                        $nome = trim($nameMatch[1]);
                    }
                    if (preg_match('/description:\s*(.+)/', $frontmatter, $descMatch)) {
                        $descricaoCurta = trim($descMatch[1]);
                    }
                    $instrucoes = trim($body);
                } else {
                    $instrucoes = $content;
                }

                AgentSkill::updateOrCreate(
                    [
                        'tenant_id' => null,
                        'nome' => $nome
                    ],
                    [
                        'origem' => 'lead_certo', // Padrão para skills globais
                        'titulo' => $titulo,
                        'descricao_curta' => substr($descricaoCurta, 0, 250),
                        'descricao_completa' => 'Importada automaticamente da pasta _global/skills',
                        'instrucoes_base' => $instrucoes,
                        'ativa' => true,
                    ]
                );
                
                $this->line("Sincronizada: {$nome}");
                $count++;
            }
        }

        $this->info("Sincronização concluída! {$count} skills importadas/atualizadas.");
    }
}
