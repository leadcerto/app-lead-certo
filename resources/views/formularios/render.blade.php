<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $formulario->nome }}</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        body {
            margin: 0;
            padding: 20px 16px;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            font-size: 15px;
            background: transparent;
            color: #1a1a1a;
        }
        .form-title { font-size: 18px; font-weight: 700; margin-bottom: 18px; color: #111; }
        .campo { margin-bottom: 14px; }
        label { display: block; font-size: 13px; font-weight: 500; color: #444; margin-bottom: 4px; }
        label .obrig { color: #e53e3e; margin-left: 2px; }
        input[type=text], input[type=email], input[type=tel], input[type=number],
        select, textarea {
            width: 100%;
            padding: 10px 12px;
            border: 1.5px solid #d1d5db;
            border-radius: 8px;
            font-size: 15px;
            outline: none;
            transition: border-color .15s;
            background: #fff;
        }
        input:focus, select:focus, textarea:focus { border-color: #22c55e; }
        textarea { resize: vertical; min-height: 80px; }
        .btn {
            width: 100%;
            padding: 12px;
            background: #16a34a;
            color: #fff;
            border: none;
            border-radius: 8px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            margin-top: 6px;
            transition: background .15s;
        }
        .btn:hover:not(:disabled) { background: #15803d; }
        .btn:disabled { opacity: .6; cursor: not-allowed; }
        .erro-campo { font-size: 12px; color: #dc2626; margin-top: 3px; }
        .sucesso {
            display: none;
            text-align: center;
            padding: 30px 16px;
        }
        .sucesso .icone { font-size: 48px; margin-bottom: 10px; }
        .sucesso h2 { font-size: 18px; color: #15803d; margin: 0 0 6px; }
        .sucesso p { font-size: 14px; color: #555; margin: 0; }
        .alerta-erro {
            display: none;
            background: #fef2f2;
            border: 1px solid #fca5a5;
            border-radius: 8px;
            padding: 10px 12px;
            font-size: 13px;
            color: #b91c1c;
            margin-bottom: 12px;
        }
    </style>
</head>
<body>

<div id="form-wrap">
    <div class="form-title">{{ $formulario->nome }}</div>

    <div class="alerta-erro" id="alerta-erro"></div>

    <form id="lc-form" novalidate>
        @csrf

        {{-- Campo telefone é sempre obrigatório (chave padrão) --}}
        <div class="campo">
            <label>Telefone / WhatsApp <span class="obrig">*</span></label>
            <input type="tel" name="telefone" placeholder="(21) 99999-9999" required>
            <div class="erro-campo" id="erro-telefone"></div>
        </div>

        {{-- Campos configurados pelo franqueado --}}
        @foreach ($formulario->campos as $campo)
            @php $id = 'campo-' . $campo->chave; @endphp
            <div class="campo">
                <label for="{{ $id }}">
                    {{ $campo->rotulo }}
                    @if ($campo->obrigatorio)<span class="obrig">*</span>@endif
                </label>

                @if ($campo->tipo === 'selecao')
                    <select name="{{ $campo->chave }}" id="{{ $id }}"
                            {{ $campo->obrigatorio ? 'required' : '' }}>
                        <option value="">Selecione...</option>
                        @foreach ($campo->opcoes ?? [] as $op)
                            <option value="{{ $op }}">{{ $op }}</option>
                        @endforeach
                    </select>
                @elseif ($campo->tipo === 'area_texto')
                    <textarea name="{{ $campo->chave }}" id="{{ $id }}"
                              {{ $campo->obrigatorio ? 'required' : '' }}
                              placeholder="{{ $campo->rotulo }}"></textarea>
                @else
                    @php
                        $htmlType = match($campo->tipo) {
                            'email'   => 'email',
                            'telefone'=> 'tel',
                            'numero'  => 'number',
                            default   => 'text',
                        };
                    @endphp
                    <input type="{{ $htmlType }}" name="{{ $campo->chave }}" id="{{ $id }}"
                           {{ $campo->obrigatorio ? 'required' : '' }}
                           placeholder="{{ $campo->rotulo }}">
                @endif

                <div class="erro-campo" id="erro-{{ $campo->chave }}"></div>
            </div>
        @endforeach

        <button type="submit" class="btn" id="btn-enviar">Enviar</button>
    </form>
</div>

<div class="sucesso" id="sucesso">
    <div class="icone">✅</div>
    <h2>Recebemos seu cadastro!</h2>
    <p>Em breve entraremos em contato pelo WhatsApp.</p>
</div>

<script>
(function () {
    var formId = '{{ $formulario->uuid }}';
    var api    = '/api/formulario/' + formId + '/submit';

    function ajustarAlturaParent() {
        var h = document.body.scrollHeight;
        window.parent.postMessage({ lcFormHeight: h, lcFormId: formId }, '*');
    }

    document.getElementById('lc-form').addEventListener('submit', function (e) {
        e.preventDefault();

        var btn = document.getElementById('btn-enviar');
        btn.disabled    = true;
        btn.textContent = 'Enviando...';

        document.getElementById('alerta-erro').style.display = 'none';

        // Limpa erros anteriores
        document.querySelectorAll('.erro-campo').forEach(function (el) { el.textContent = ''; });

        // Coleta dados do form
        var dados = {};
        new FormData(e.target).forEach(function (val, key) {
            if (key !== '_token') dados[key] = val;
        });

        fetch(api, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept':       'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
            },
            body: JSON.stringify(dados),
        })
        .then(function (res) { return res.json().then(function (d) { return { ok: res.ok, data: d }; }); })
        .then(function (r) {
            if (r.ok) {
                document.getElementById('form-wrap').style.display = 'none';
                document.getElementById('sucesso').style.display = 'block';
                ajustarAlturaParent();
                return;
            }

            // Erros de validação (422)
            if (r.data.campos) {
                Object.keys(r.data.campos).forEach(function (chave) {
                    var el = document.getElementById('erro-' + chave);
                    if (el) el.textContent = r.data.campos[chave];
                });
            } else {
                var alerta = document.getElementById('alerta-erro');
                alerta.textContent  = r.data.erro || 'Erro ao enviar. Tente novamente.';
                alerta.style.display = 'block';
            }

            btn.disabled    = false;
            btn.textContent = 'Enviar';
            ajustarAlturaParent();
        })
        .catch(function () {
            var alerta = document.getElementById('alerta-erro');
            alerta.textContent  = 'Erro de conexão. Tente novamente.';
            alerta.style.display = 'block';
            btn.disabled    = false;
            btn.textContent = 'Enviar';
        });
    });

    // Comunica altura inicial ao parent
    window.addEventListener('load', ajustarAlturaParent);
})();
</script>

</body>
</html>
