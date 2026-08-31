<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tenant extends Model
{
    use HasFactory;

    protected $fillable = [
        'nome',
        'razao_social',
        'cnpj',
        'nicho',
        'status',
        'email',
        'dominio',
        'telefone',
        'cidade',
        'estado',
        'cep',
        'endereco',
        'site_url',
        'descricao_negocio',
        'publico_alvo',
        'diferenciais',
        'instagram_url',
        'facebook_url',
        'youtube_url',
        'linkedin_url',
        'gmb_url',
        'google_conta_email',
        'google_conta_senha',
        'google_ads_customer_id',
        'google_ads_developer_token',
        'google_ads_client_id',
        'google_ads_client_secret',
        'google_ads_refresh_token',
        'google_business_location_id',
        'meta_bm_id',
        'meta_ad_account_id',
        'meta_pixel_id',
        'meta_access_token',
        'openrouter_api_key',
        'whatsapp_status',
        'whatsapp_phone',
        'whatsapp_connected_since',
        'uazapi_instance_name',
        'uazapi_instance_token',
        'uazapi_webhook_token',
        'secretaria_token',
        'secretaria_mensagem_inicial',
        'secretaria_mensagem_inicial_imagem_url',
        'secretaria_envio_ativo',
        'ia_contexto',
        'tabela_precos_pdf_path',
        'tabela_precos_texto',
    ];

    /**
     * Campos confidenciais com criptografia transparente no banco de dados.
     */
    protected $casts = [
        'secretaria_envio_ativo'     => 'boolean',
        'google_conta_senha'         => 'encrypted',
        'google_ads_developer_token' => 'encrypted',
        'google_ads_client_id'       => 'encrypted',
        'google_ads_client_secret'   => 'encrypted',
        'google_ads_refresh_token'   => 'encrypted',
        'meta_access_token'          => 'encrypted',
        'openrouter_api_key'         => 'encrypted',
    ];

    // ── Relacionamentos ──

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(TicketAtendimento::class);
    }

    public function personas(): HasMany
    {
        return $this->hasMany(SdrPersona::class);
    }

    public function canais(): HasMany
    {
        return $this->hasMany(WhatsappCanal::class);
    }

    public function perfisGmb(): HasMany
    {
        return $this->hasMany(PerfilGmb::class);
    }

    public function gmbPosts(): HasMany
    {
        return $this->hasMany(GmbPost::class);
    }

    // ── Helpers de Status das Conexões ──

    public function temGoogleAds(): bool
    {
        return !empty($this->google_ads_customer_id) && !empty($this->google_ads_refresh_token);
    }

    public function temMetaAds(): bool
    {
        return !empty($this->meta_ad_account_id) && !empty($this->meta_access_token);
    }

    public function temGmb(): bool
    {
        return !empty($this->gmb_url) || !empty($this->google_business_location_id) || $this->perfisGmb()->exists();
    }

    public function temWhatsappConectado(): bool
    {
        return $this->whatsapp_status === 'connected' || $this->canais()->where('status', 'conectado')->exists();
    }
}
