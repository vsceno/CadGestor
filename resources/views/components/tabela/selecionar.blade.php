{{--
    Caixa de seleção de uma linha (seleção em lote). O valor vai no campo
    "selecionados[]" do formulário de lote.
--}}
@props(['valor', 'rotulo' => 'Selecionar linha', 'form' => null])

<td class="w-10">
    <input type="checkbox" name="selecionados[]" value="{{ $valor }}" class="size-4 accent-primaria"
        data-selecionar-linha aria-label="{{ $rotulo }}" @if ($form) form="{{ $form }}" @endif>
</td>
