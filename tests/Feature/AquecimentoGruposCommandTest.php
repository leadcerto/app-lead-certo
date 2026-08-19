<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\WhatsappCanal;
use App\Models\WhatsappGrupoPost;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AquecimentoGruposCommandTest extends TestCase
{
    use RefreshDatabase;

    private function canalComGrupos(array $overrides = []): WhatsappCanal
    {
        $tenant = Tenant::factory()->create();

        return WhatsappCanal::factory()->create(array_merge([
            'tenant_id' => $tenant->id, 'tipo' => 'nao_oficial', 'provider' => 'uazapi',
            'config' => ['instance_token' => 'token-aquecimento'],
        ], $overrides));
    }

    public function test_posta_em_grupo_que_ainda_nao_recebeu_post_hoje(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-20 14:00:00', 'America/Sao_Paulo'));

        Http::fake([
            '*/group/list' => Http::response(['groups' => [
                ['chatid' => '111@g.us', 'name' => 'Figurinhas Top'],
            ]], 200),
            '*/send/text' => Http::response(['id' => 'abc'], 200),
        ]);

        $canal = $this->canalComGrupos();

        $this->artisan('whatsapp:aquecimento-grupos')->assertExitCode(0);

        $this->assertDatabaseHas('whatsapp_grupo_posts', [
            'whatsapp_canal_id' => $canal->id, 'grupo_chatid' => '111@g.us',
        ]);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'send/text'));

        Carbon::setTestNow();
    }

    public function test_nao_posta_de_novo_no_mesmo_grupo_no_mesmo_dia(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-20 14:00:00', 'America/Sao_Paulo'));

        Http::fake([
            '*/group/list' => Http::response(['groups' => [
                ['chatid' => '111@g.us', 'name' => 'Figurinhas Top'],
            ]], 200),
            '*/send/text' => Http::response(['id' => 'abc'], 200),
        ]);

        $canal = $this->canalComGrupos();
        WhatsappGrupoPost::create([
            'whatsapp_canal_id' => $canal->id, 'grupo_chatid' => '111@g.us',
            'conteudo' => '😂', 'postado_em' => now(),
        ]);

        $this->artisan('whatsapp:aquecimento-grupos')->assertExitCode(0);

        $this->assertSame(1, WhatsappGrupoPost::where('whatsapp_canal_id', $canal->id)->count());
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'send/text'));

        Carbon::setTestNow();
    }

    public function test_nao_posta_durante_a_madrugada(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-20 03:00:00', 'America/Sao_Paulo'));

        Http::fake([
            '*/group/list' => Http::response(['groups' => [
                ['chatid' => '111@g.us', 'name' => 'Figurinhas Top'],
            ]], 200),
            '*/send/text' => Http::response(['id' => 'abc'], 200),
        ]);

        $this->canalComGrupos();

        $this->artisan('whatsapp:aquecimento-grupos')->assertExitCode(0);

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'send/text'));

        Carbon::setTestNow();
    }

    public function test_ignora_canal_sem_token(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-20 14:00:00', 'America/Sao_Paulo'));

        $this->canalComGrupos(['config' => []]);

        $this->artisan('whatsapp:aquecimento-grupos')->assertExitCode(0);

        Carbon::setTestNow();
    }

    public function test_dry_run_nao_envia_nem_grava(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-20 14:00:00', 'America/Sao_Paulo'));

        Http::fake([
            '*/group/list' => Http::response(['groups' => [
                ['chatid' => '111@g.us', 'name' => 'Figurinhas Top'],
            ]], 200),
            '*/send/text' => Http::response(['id' => 'abc'], 200),
        ]);

        $this->canalComGrupos();

        $this->artisan('whatsapp:aquecimento-grupos --dry-run')->assertExitCode(0);

        $this->assertDatabaseCount('whatsapp_grupo_posts', 0);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'send/text'));

        Carbon::setTestNow();
    }
}
