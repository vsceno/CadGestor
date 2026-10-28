/*
 * CadGestor - comportamentos da interface (JavaScript puro, sem dependências).
 *
 * A Content-Security-Policy proíbe scripts inline: tudo é ligado aqui por
 * atributos data-*. Sem JavaScript, as telas continuam funcionando (links e
 * formulários comuns); o script só acrescenta conforto.
 */

const umAno = 60 * 60 * 24 * 365;

function gravarCookie(nome, valor) {
    document.cookie = `${nome}=${encodeURIComponent(valor)}; path=/; max-age=${umAno}; SameSite=Lax`;
}

function lerLocal(chave, padrao = null) {
    try {
        return window.localStorage.getItem(chave) ?? padrao;
    } catch {
        return padrao;
    }
}

function gravarLocal(chave, valor) {
    try {
        window.localStorage.setItem(chave, valor);
    } catch {
        // Navegador sem armazenamento (modo privado): a preferência só não é lembrada.
    }
}

/* ------------------------------------------------------------------------
 * Tema: sistema -> claro -> escuro -> sistema
 * --------------------------------------------------------------------- */
const ordemTemas = ['sistema', 'claro', 'escuro'];
const rotulosTema = { sistema: 'Tema: igual ao sistema', claro: 'Tema: claro', escuro: 'Tema: escuro' };

document.addEventListener('click', (evento) => {
    const botao = evento.target.closest('[data-alternar-tema]');
    if (!botao) {
        return;
    }

    const atual = botao.dataset.temaAtual || 'sistema';
    const proximo = ordemTemas[(ordemTemas.indexOf(atual) + 1) % ordemTemas.length];

    if (proximo === 'sistema') {
        document.documentElement.removeAttribute('data-tema');
    } else {
        document.documentElement.setAttribute('data-tema', proximo);
    }

    gravarCookie('cg_tema', proximo);

    document.querySelectorAll('[data-alternar-tema]').forEach((outro) => {
        outro.dataset.temaAtual = proximo;
        outro.setAttribute('aria-label', `${rotulosTema[proximo]} (clique para mudar)`);
        outro.title = rotulosTema[proximo];
        outro.querySelectorAll('[data-icone-tema]').forEach((icone) => {
            icone.toggleAttribute('hidden', icone.dataset.iconeTema !== proximo);
        });
    });
});

/* ------------------------------------------------------------------------
 * Menu lateral: recolher (desktop) e abrir/fechar (celular, botão "Mais")
 * --------------------------------------------------------------------- */
const menu = document.querySelector('[data-menu-lateral]');
const fundoMenu = document.querySelector('[data-fundo-menu]');

function abrirMenuCelular(abrir) {
    if (!menu) {
        return;
    }
    menu.dataset.aberto = abrir ? 'sim' : 'nao';
    fundoMenu?.classList.toggle('hidden', !abrir);
    document.querySelectorAll('[data-abrir-menu]').forEach((b) => b.setAttribute('aria-expanded', String(abrir)));
    if (abrir) {
        menu.querySelector('a, button')?.focus();
    }
}

document.addEventListener('click', (evento) => {
    if (evento.target.closest('[data-recolher-menu]') && menu) {
        const recolher = menu.dataset.recolhido !== 'sim';
        menu.dataset.recolhido = recolher ? 'sim' : 'nao';
        gravarCookie('cg_menu', recolher ? 'recolhido' : 'expandido');

        const botao = evento.target.closest('[data-recolher-menu]');
        botao.setAttribute('aria-label', recolher ? 'Expandir menu' : 'Recolher menu');
        return;
    }

    if (evento.target.closest('[data-abrir-menu]')) {
        abrirMenuCelular(true);
        return;
    }

    if (evento.target.closest('[data-fechar-menu]') || evento.target.closest('[data-fundo-menu]')) {
        abrirMenuCelular(false);
    }
});

document.addEventListener('keydown', (evento) => {
    if (evento.key === 'Escape' && menu?.dataset.aberto === 'sim') {
        abrirMenuCelular(false);
    }
});

/* Busca global no celular: o botão de lupa abre o campo sobre o cabeçalho. */
const buscaGlobal = document.querySelector('[data-busca-global]');

document.addEventListener('click', (evento) => {
    if (evento.target.closest('[data-abrir-busca]') && buscaGlobal) {
        buscaGlobal.dataset.aberta = 'sim';
        requestAnimationFrame(() => buscaGlobal.querySelector('input')?.focus());
    } else if (buscaGlobal?.dataset.aberta === 'sim' && !buscaGlobal.contains(evento.target)) {
        buscaGlobal.dataset.aberta = 'nao';
    }
});

document.addEventListener('keydown', (evento) => {
    if (evento.key === 'Escape' && buscaGlobal?.dataset.aberta === 'sim') {
        buscaGlobal.dataset.aberta = 'nao';
        document.querySelector('[data-abrir-busca]')?.focus();
    }
});

/* Menus suspensos (<details> com summary "list-none"): fecham ao clicar fora ou com Esc. */
const menusSuspensosAbertos = () =>
    [...document.querySelectorAll('details[open]')].filter((d) =>
        d.querySelector(':scope > summary')?.classList.contains('list-none'),
    );

document.addEventListener('click', (evento) => {
    menusSuspensosAbertos().forEach((detalhes) => {
        if (!detalhes.contains(evento.target)) {
            detalhes.removeAttribute('open');
        }
    });
});

document.addEventListener('keydown', (evento) => {
    if (evento.key !== 'Escape') {
        return;
    }
    menusSuspensosAbertos().forEach((detalhes) => {
        detalhes.removeAttribute('open');
        detalhes.querySelector('summary')?.focus();
    });
});

/* ------------------------------------------------------------------------
 * Modal de confirmação (<dialog>)
 * --------------------------------------------------------------------- */
document.addEventListener('click', (evento) => {
    const abrir = evento.target.closest('[data-abrir-modal]');
    if (abrir) {
        const modal = document.getElementById(abrir.dataset.abrirModal);
        if (modal instanceof HTMLDialogElement) {
            evento.preventDefault();
            modal.showModal();
            modal.querySelector('[data-exigir-texto]')?.focus();
        }
        return;
    }

    if (evento.target.closest('[data-fechar-modal]')) {
        evento.target.closest('dialog')?.close();
    }
});

// "Digite o nome para confirmar": o botão só libera com o texto exato.
document.addEventListener('input', (evento) => {
    const campo = evento.target.closest('[data-exigir-texto]');
    if (!campo) {
        return;
    }
    const botao = campo.closest('form')?.querySelector('[data-botao-confirmar]');
    if (botao) {
        botao.disabled = campo.value.trim() !== campo.dataset.exigirTexto;
    }
});

/* ------------------------------------------------------------------------
 * Alertas que podem ser fechados (sucesso/informação; erro nunca some sozinho)
 * --------------------------------------------------------------------- */
document.addEventListener('click', (evento) => {
    const fechar = evento.target.closest('[data-fechar-alerta]');
    if (fechar) {
        fechar.closest('[data-alerta]')?.remove();
    }
});

/* ------------------------------------------------------------------------
 * Máscaras brasileiras: <input data-mascara="cpf|cnpj|cep|telefone">
 * (só conforto ao digitar; o servidor valida de novo)
 * --------------------------------------------------------------------- */
const mascaras = {
    cpf(valor) {
        const d = valor.replace(/\D/g, '').slice(0, 11);
        return d
            .replace(/(\d{3})(\d)/, '$1.$2')
            .replace(/(\d{3})(\d)/, '$1.$2')
            .replace(/(\d{3})(\d{1,2})$/, '$1-$2');
    },
    cnpj(valor) {
        // Aceita o CNPJ alfanumérico: 12 posições letra/número + 2 dígitos.
        const v = valor.toUpperCase().replace(/[^0-9A-Z]/g, '').slice(0, 14);
        return v
            .replace(/^(\w{2})(\w)/, '$1.$2')
            .replace(/^(\w{2})\.(\w{3})(\w)/, '$1.$2.$3')
            .replace(/\.(\w{3})(\w)/, '.$1/$2')
            .replace(/(\w{4})(\d{1,2})$/, '$1-$2');
    },
    cep(valor) {
        return valor.replace(/\D/g, '').slice(0, 8).replace(/(\d{5})(\d)/, '$1-$2');
    },
    telefone(valor) {
        const d = valor.replace(/\D/g, '').slice(0, 11);
        if (d.length > 10) {
            return d.replace(/(\d{2})(\d{5})(\d{1,4})/, '($1) $2-$3');
        }
        return d.replace(/(\d{2})(\d{4})(\d{0,4})/, '($1) $2-$3').replace(/-$/, '').replace(/^\((\d{0,2})$/, '($1');
    },
};

document.addEventListener('input', (evento) => {
    const tipo = evento.target.dataset?.mascara;
    if (tipo && mascaras[tipo]) {
        evento.target.value = mascaras[tipo](evento.target.value);
    }
});

/* ------------------------------------------------------------------------
 * Tabelas: densidade, colunas visíveis e seleção em lote
 * --------------------------------------------------------------------- */
document.querySelectorAll('[data-tabela]').forEach((bloco) => {
    const id = bloco.dataset.tabela;
    const tabela = bloco.querySelector('table');
    const botaoDensidade = bloco.querySelector('[data-alternar-densidade]');

    function aplicarDensidade(densidade) {
        tabela.dataset.densidade = densidade;
        botaoDensidade?.setAttribute('aria-pressed', String(densidade === 'compacta'));
    }

    aplicarDensidade(lerLocal(`cg_tabela_${id}_densidade`, 'normal'));
    botaoDensidade?.addEventListener('click', () => {
        const nova = tabela.dataset.densidade === 'compacta' ? 'normal' : 'compacta';
        aplicarDensidade(nova);
        gravarLocal(`cg_tabela_${id}_densidade`, nova);
    });

    // Colunas escondidas pelo usuário.
    const chaveColunas = `cg_tabela_${id}_ocultas`;
    let ocultas = [];
    try {
        ocultas = JSON.parse(lerLocal(chaveColunas, '[]')) || [];
    } catch {
        ocultas = [];
    }

    function aplicarColunas() {
        bloco.querySelectorAll('[data-coluna]').forEach((celula) => {
            celula.hidden = ocultas.includes(celula.dataset.coluna);
        });
        bloco.querySelectorAll('[data-alternar-coluna]').forEach((caixa) => {
            caixa.checked = !ocultas.includes(caixa.dataset.alternarColuna);
        });
    }

    aplicarColunas();
    bloco.querySelectorAll('[data-alternar-coluna]').forEach((caixa) => {
        caixa.addEventListener('change', () => {
            const coluna = caixa.dataset.alternarColuna;
            ocultas = caixa.checked ? ocultas.filter((c) => c !== coluna) : [...ocultas, coluna];
            gravarLocal(chaveColunas, JSON.stringify(ocultas));
            aplicarColunas();
        });
    });

    // Seleção em lote.
    const todos = bloco.querySelector('[data-selecionar-todos]');
    const linhas = () => [...bloco.querySelectorAll('[data-selecionar-linha]')];
    const barra = bloco.querySelector('[data-barra-lote]');
    const contador = bloco.querySelector('[data-contador-lote]');

    function atualizarLote() {
        const marcadas = linhas().filter((c) => c.checked).length;
        if (contador) {
            contador.textContent = String(marcadas);
        }
        if (barra) {
            barra.hidden = marcadas === 0;
        }
        if (todos) {
            todos.checked = marcadas > 0 && marcadas === linhas().length;
            todos.indeterminate = marcadas > 0 && marcadas < linhas().length;
        }
    }

    todos?.addEventListener('change', () => {
        linhas().forEach((c) => {
            c.checked = todos.checked;
        });
        atualizarLote();
    });
    bloco.addEventListener('change', (evento) => {
        if (evento.target.matches('[data-selecionar-linha]')) {
            atualizarLote();
        }
    });
});

/* ------------------------------------------------------------------------
 * Rascunho automático de formulários longos: <form data-rascunho="chave">
 *
 * Guardado no sessionStorage (apagado ao fechar a aba) e NUNCA inclui senha,
 * token, campos ocultos ou campos marcados com data-sem-rascunho (ex.: CPF).
 * O rascunho é apagado quando o formulário é enviado.
 * --------------------------------------------------------------------- */
function camposDoRascunho(formulario) {
    return [...formulario.elements].filter(
        (campo) =>
            campo.name &&
            !campo.disabled &&
            !['password', 'hidden', 'file', 'submit', 'button'].includes(campo.type) &&
            !campo.hasAttribute('data-sem-rascunho') &&
            !campo.name.startsWith('_'),
    );
}

document.querySelectorAll('form[data-rascunho]').forEach((formulario) => {
    const chave = `cg_rascunho_${formulario.dataset.rascunho}`;
    let salvo = null;

    try {
        salvo = JSON.parse(window.sessionStorage.getItem(chave) || 'null');
    } catch {
        salvo = null;
    }

    // Só restaura em campos vazios: o que veio do servidor (ex.: após erro) tem prioridade.
    if (salvo) {
        let restaurou = false;
        camposDoRascunho(formulario).forEach((campo) => {
            if (!(campo.name in salvo)) {
                return;
            }
            if (campo.type === 'checkbox' || campo.type === 'radio') {
                if (!formulario.querySelector(`[name="${CSS.escape(campo.name)}"]:checked`)) {
                    campo.checked = salvo[campo.name] === campo.value;
                    restaurou ||= campo.checked;
                }
            } else if (campo.value === '') {
                campo.value = salvo[campo.name];
                restaurou ||= campo.value !== '';
            }
        });
        formulario.querySelector('[data-aviso-rascunho]')?.toggleAttribute('hidden', !restaurou);
    }

    let espera;
    formulario.addEventListener('input', () => {
        clearTimeout(espera);
        espera = setTimeout(() => {
            const dados = {};
            camposDoRascunho(formulario).forEach((campo) => {
                if (campo.type === 'checkbox' || campo.type === 'radio') {
                    if (campo.checked) {
                        dados[campo.name] = campo.value;
                    }
                } else {
                    dados[campo.name] = campo.value;
                }
            });
            try {
                window.sessionStorage.setItem(chave, JSON.stringify(dados));
            } catch {
                // Sem armazenamento: segue sem rascunho.
            }
        }, 600);
    });

    formulario.addEventListener('submit', () => {
        try {
            window.sessionStorage.removeItem(chave);
        } catch {
            // nada a fazer
        }
    });
});
