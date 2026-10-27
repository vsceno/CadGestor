/*
 * CadGestor - comportamentos de interface (JavaScript puro, sem dependências).
 * A CSP proíbe scripts inline, por isso tudo é ligado aqui via atributos data-*.
 */
(function () {
    'use strict';

    // Confirmação antes de enviar formulários sensíveis: <form data-confirmar="Mensagem">
    document.addEventListener('submit', function (evento) {
        var mensagem = evento.target.getAttribute('data-confirmar');
        if (mensagem && !window.confirm(mensagem)) {
            evento.preventDefault();
        }
    });

    // Copiar conteúdo de um elemento: <button data-copiar="id-do-elemento">
    document.addEventListener('click', function (evento) {
        var botao = evento.target.closest('[data-copiar]');
        if (!botao) {
            return;
        }
        var alvo = document.getElementById(botao.getAttribute('data-copiar'));
        if (alvo && navigator.clipboard) {
            navigator.clipboard.writeText(alvo.textContent.trim()).then(function () {
                botao.textContent = 'Copiado!';
            });
        }
    });

    // Máscaras simples de digitação: <input data-mascara="cpf|cnpj|cep|telefone">
    var mascaras = {
        cpf: function (v) {
            v = v.replace(/\D/g, '').slice(0, 11);
            return v.replace(/(\d{3})(\d)/, '$1.$2').replace(/(\d{3})(\d)/, '$1.$2').replace(/(\d{3})(\d{1,2})$/, '$1-$2');
        },
        cnpj: function (v) {
            // CNPJ alfanumérico: 12 posições letra/número + 2 dígitos.
            v = v.toUpperCase().replace(/[^0-9A-Z]/g, '').slice(0, 14);
            return v.replace(/^(\w{2})(\w)/, '$1.$2').replace(/^(\w{2})\.(\w{3})(\w)/, '$1.$2.$3')
                .replace(/\.(\w{3})(\w)/, '.$1/$2').replace(/(\w{4})(\d{1,2})$/, '$1-$2');
        },
        cep: function (v) {
            v = v.replace(/\D/g, '').slice(0, 8);
            return v.replace(/(\d{5})(\d)/, '$1-$2');
        },
        telefone: function (v) {
            v = v.replace(/\D/g, '').slice(0, 11);
            if (v.length > 10) {
                return v.replace(/(\d{2})(\d{5})(\d{1,4})/, '($1) $2-$3');
            }
            return v.replace(/(\d{2})(\d{4})(\d{0,4})/, '($1) $2-$3').replace(/-$/, '');
        }
    };

    document.addEventListener('input', function (evento) {
        var tipo = evento.target.getAttribute && evento.target.getAttribute('data-mascara');
        if (tipo && mascaras[tipo]) {
            evento.target.value = mascaras[tipo](evento.target.value);
        }
    });

    // Linhas dinâmicas (horários/regras): <div data-linhas> + <template data-modelo> + [data-adicionar]
    document.addEventListener('click', function (evento) {
        var adicionar = evento.target.closest('[data-adicionar]');
        if (adicionar) {
            var bloco = document.getElementById(adicionar.getAttribute('data-adicionar'));
            var modelo = bloco && bloco.querySelector('template[data-modelo]');
            if (modelo) {
                var indice = Date.now();
                var html = modelo.innerHTML.replace(/__i__/g, String(indice));
                modelo.insertAdjacentHTML('beforebegin', html);
            }
            return;
        }
        var remover = evento.target.closest('[data-remover-linha]');
        if (remover) {
            var linha = remover.closest('.linha-horario');
            if (linha) {
                linha.remove();
            }
        }
    });

    // Captura facial pela webcam: a foto vai para o <input type="file"> do formulário
    // e só existe em memória no servidor até virar template (não é gravada).
    document.addEventListener('click', function (evento) {
        var abrir = evento.target.closest('[data-abrir-camera]');
        var tirar = evento.target.closest('[data-tirar-foto]');
        if (!abrir && !tirar) {
            return;
        }
        var form = (abrir || tirar).closest('[data-captura-facial]');
        var video = form.querySelector('[data-video]');
        var canvas = form.querySelector('[data-canvas]');
        var entrada = form.querySelector('input[type=file]');

        if (abrir) {
            if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                window.alert('Câmera indisponível neste navegador. Selecione uma imagem.');
                return;
            }
            navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user' }, audio: false }).then(function (fluxo) {
                video.srcObject = fluxo;
                video.hidden = false;
                form.querySelector('[data-tirar-foto]').hidden = false;
            }).catch(function () {
                window.alert('Não foi possível acessar a câmera. Selecione uma imagem.');
            });
            return;
        }

        canvas.width = video.videoWidth;
        canvas.height = video.videoHeight;
        canvas.getContext('2d').drawImage(video, 0, 0);
        canvas.hidden = false;
        video.srcObject.getTracks().forEach(function (trilha) { trilha.stop(); });
        video.hidden = true;
        canvas.toBlob(function (blob) {
            var transferencia = new DataTransfer();
            transferencia.items.add(new File([blob], 'captura.jpg', { type: 'image/jpeg' }));
            entrada.files = transferencia.files;
        }, 'image/jpeg', 0.9);
    });

    // Envio automático de filtros ao trocar um select: <select data-auto-enviar>
    document.addEventListener('change', function (evento) {
        if (evento.target.hasAttribute && evento.target.hasAttribute('data-auto-enviar')) {
            evento.target.form.submit();
        }
    });
})();
