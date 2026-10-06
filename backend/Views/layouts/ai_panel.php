<?php

declare(strict_types=1);

use App\Core\Url;

/**
 * Painel do assistente (SÓ INTERFACE — nenhuma chamada de IA). Desktop: painel lateral aberto pelo selo "IA"
 * da trilha. Mobile: botão flutuante abre o painel em tela cheia. Fecha com Esc, clique fora ou X.
 */
?>
<button type="button" class="ai-fab" data-ai-open aria-haspopup="dialog" aria-controls="ai-panel" aria-label="Abrir assistente">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg>
</button>

<div class="ai-backdrop" data-ai-backdrop hidden></div>
<aside class="ai-panel" id="ai-panel" data-ai-panel role="dialog" aria-label="Assistente" aria-modal="true" hidden>
    <header class="ai-panel__header">
        <span class="ai-panel__logo">IA</span>
        <strong>Assistente <span class="ai-panel__soon">— em breve</span></strong>
        <button type="button" class="ai-panel__close" data-ai-close aria-label="Fechar assistente">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><line x1="6" y1="6" x2="18" y2="18"/><line x1="18" y1="6" x2="6" y2="18"/></svg>
        </button>
    </header>
    <form class="global-search ai-panel__search" method="get" action="<?= htmlspecialchars(Url::to('/clientes'), ENT_QUOTES, 'UTF-8'); ?>" role="search">
        <label class="visually-hidden" for="ai-panel-search">Buscar</label>
        <input id="ai-panel-search" type="search" name="q" data-ai-search placeholder="Buscar cliente, login, CPF/CNPJ, contrato ou equipamento..." autocomplete="off">
    </form>
</aside>
