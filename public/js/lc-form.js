/**
 * Lead Certo — Form Widget v1
 * Cole no site: <script src="https://app.leadcerto.app.br/js/lc-form.js" data-form-id="UUID" async></script>
 * Cria um iframe apontando para /f/{uuid}. Nenhum token de autenticação exposto.
 */
(function () {
    var script = document.currentScript || (function () {
        var scripts = document.getElementsByTagName('script');
        return scripts[scripts.length - 1];
    })();

    var formId = script.getAttribute('data-form-id');
    var width  = script.getAttribute('data-width')  || '100%';
    var height = script.getAttribute('data-height') || '520px';

    if (!formId) {
        console.error('[LeadCerto] Atributo data-form-id não encontrado.');
        return;
    }

    var base = script.src.replace('/js/lc-form.js', '');
    var src  = base + '/f/' + formId;

    var iframe = document.createElement('iframe');
    iframe.src             = src;
    iframe.width           = width;
    iframe.style.width     = width;
    iframe.style.height    = height;
    iframe.style.border    = 'none';
    iframe.style.overflow  = 'hidden';
    iframe.scrolling       = 'no';
    iframe.title           = 'Formulário de Contato';
    iframe.setAttribute('loading', 'lazy');

    // Redimensiona iframe conforme o conteúdo interno comunica via postMessage
    window.addEventListener('message', function (e) {
        if (e.data && e.data.lcFormHeight && e.data.lcFormId === formId) {
            iframe.style.height = e.data.lcFormHeight + 'px';
        }
    });

    script.parentNode.insertBefore(iframe, script.nextSibling);
})();
