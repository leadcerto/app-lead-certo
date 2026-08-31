<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            // Dados Corporativos e Localização
            $table->string('razao_social')->nullable()->after('nome');
            $table->string('cnpj', 30)->nullable()->after('razao_social');
            $table->string('cidade', 100)->nullable()->after('telefone');
            $table->string('estado', 10)->nullable()->after('cidade');
            $table->string('cep', 20)->nullable()->after('estado');
            $table->string('endereco', 255)->nullable()->after('cep');
            $table->string('site_url', 500)->nullable()->after('dominio');

            // Contexto Estratégico para Anúncios e IA
            $table->text('descricao_negocio')->nullable()->after('nicho');
            $table->text('publico_alvo')->nullable()->after('descricao_negocio');
            $table->text('diferenciais')->nullable()->after('publico_alvo');

            // Redes Sociais
            $table->string('instagram_url', 500)->nullable()->after('site_url');
            $table->string('facebook_url', 500)->nullable()->after('instagram_url');
            $table->string('youtube_url', 500)->nullable()->after('facebook_url');
            $table->string('linkedin_url', 500)->nullable()->after('youtube_url');
            $table->string('gmb_url', 500)->nullable()->after('linkedin_url');

            // Cofre Seguro de Credenciais: Google & Google Ads
            $table->string('google_conta_email')->nullable()->after('gmb_url');
            $table->text('google_conta_senha')->nullable()->after('google_conta_email');
            $table->string('google_ads_customer_id', 100)->nullable()->after('google_conta_senha');
            $table->text('google_ads_developer_token')->nullable()->after('google_ads_customer_id');
            $table->text('google_ads_client_id')->nullable()->after('google_ads_developer_token');
            $table->text('google_ads_client_secret')->nullable()->after('google_ads_client_id');
            $table->text('google_ads_refresh_token')->nullable()->after('google_ads_client_secret');
            $table->string('google_business_location_id', 150)->nullable()->after('google_ads_refresh_token');

            // Cofre Seguro de Credenciais: Meta Ads
            $table->string('meta_bm_id', 100)->nullable()->after('google_business_location_id');
            $table->string('meta_ad_account_id', 100)->nullable()->after('meta_bm_id');
            $table->string('meta_pixel_id', 100)->nullable()->after('meta_ad_account_id');
            $table->text('meta_access_token')->nullable()->after('meta_pixel_id');

            // Provedor IA Personalizado (opcional)
            $table->text('openrouter_api_key')->nullable()->after('meta_access_token');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn([
                'razao_social',
                'cnpj',
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
            ]);
        });
    }
};
