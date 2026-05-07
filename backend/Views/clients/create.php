<?php

declare(strict_types=1);

use App\Core\Url;

$form = is_array($form ?? null) ? $form : [];
$clearDraftKeys = is_array($clearDraftKeys ?? null) ? $clearDraftKeys : [];
$dueDays = is_array($dueDays ?? null) ? $dueDays : [];
$fieldValue = static function (string $name, string $default = '') use ($form): string {
    return htmlspecialchars((string) ($form[$name] ?? $default), ENT_QUOTES, 'UTF-8');
};
$fieldChecked = static function (string $name, string $value, bool $default = false) use ($form): string {
    $current = (string) ($form[$name] ?? ($default ? $value : ''));

    return $current === $value ? 'selected' : '';
};
$fieldSelected = static function (string $name, string $value, string $default = '') use ($form): string {
    $current = (string) ($form[$name] ?? $default);

    return $current === $value ? 'selected' : '';
};
$draftId = (string) ($draftId ?? '');
$checkpointToken = (string) ($checkpointToken ?? '');
$draftKey = (string) ($draftKey ?? 'client-create');
$commercial = is_array($contractCommercial ?? null) ? $contractCommercial : [];
$maxAdhesionInstallments = max(1, (int) ($commercial['parcelas_maximas_adesao'] ?? 3));
$loggedUserName = trim((string) (($user['name'] ?? $user['login'] ?? '')));
$initialInstallType = strtolower(trim((string) ($form['tipo_instalacao'] ?? ($defaultInstallType ?? 'fibra'))));
$initialInstallType = in_array($initialInstallType, ['fibra', 'radio'], true) ? $initialInstallType : 'fibra';
$initialAdhesionType = strtolower(trim((string) ($form['tipo_adesao'] ?? '')));
if (!in_array($initialAdhesionType, ['cheia', 'promocional', 'isenta'], true)) {
    $initialAdhesionType = $initialInstallType === 'fibra' ? 'isenta' : 'cheia';
}
$initialAdhesionInstallments = max(1, min($maxAdhesionInstallments, (int) ($form['parcelas_adesao'] ?? 1)));
$initialAdhesionFidelity = max(1, (int) ($form['fidelidade_meses'] ?? ($commercial['fidelidade_meses_padrao'] ?? 12)));
$initialAdhesionValue = (string) ($form['valor_adesao'] ?? '');
$initialBenefitAuthorizer = (string) ($form['beneficio_concedido_por'] ?? ($loggedUserName !== '' ? $loggedUserName : ''));
$baseAdhesionValue = (float) ($commercial['valor_adesao_padrao'] ?? 0);
$promoAdhesionValue = (float) ($commercial['valor_adesao_promocional'] ?? 0);
$promoDiscountPercent = (float) ($commercial['percentual_desconto_promocional'] ?? 0);
$defaultAdhesionValue = match ($initialAdhesionType) {
    'isenta' => 0.0,
    'promocional' => $promoAdhesionValue > 0
        ? $promoAdhesionValue
        : max(0.0, $baseAdhesionValue - ($baseAdhesionValue * max(0.0, $promoDiscountPercent) / 100)),
    default => max(0.0, $baseAdhesionValue),
};
$defaultBenefitValue = $initialAdhesionType === 'isenta'
    ? number_format($baseAdhesionValue, 2, ',', '.')
    : number_format(max(0.0, $baseAdhesionValue - $defaultAdhesionValue), 2, ',', '.');
$defaultParcelValue = $initialAdhesionInstallments > 0
    ? number_format($defaultAdhesionValue / $initialAdhesionInstallments, 2, ',', '.')
    : '0,00';
$formCity = (string) ($form['cidade'] ?? '');
$cityExists = false;

foreach (($cities ?? []) as $city) {
    if ($formCity !== '' && strcasecmp($formCity, (string) ($city['name'] ?? '')) === 0) {
        $cityExists = true;
        break;
    }
}

ob_start();
?>
<section class="page-header">
    <div>
        <p class="section-heading__eyebrow">Cadastro</p>
        <h1>Novo Cliente</h1>
        <p class="page-description">A tela mostra apenas o que o técnico precisa preencher. O restante fica com padrão automático no backend.</p>
    </div>
</section>

<?php if (!empty($flash)): ?>
    <section class="alert <?= htmlspecialchars(($flash['type'] ?? 'success') === 'error' ? 'alert--error' : 'alert--success', ENT_QUOTES, 'UTF-8'); ?>">
        <?= htmlspecialchars((string) ($flash['message'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
    </section>
<?php endif; ?>

<form
    class="content-grid content-grid--form"
    method="post"
    action="<?= htmlspecialchars(Url::to('/clientes/novo'), ENT_QUOTES, 'UTF-8'); ?>"
    enctype="multipart/form-data"
    data-autosave-form
    data-draft-key="<?= htmlspecialchars($draftKey, ENT_QUOTES, 'UTF-8'); ?>"
    data-clear-draft-keys="<?= htmlspecialchars(json_encode($clearDraftKeys, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]', ENT_QUOTES, 'UTF-8'); ?>"
    data-clear-draft-endpoint="<?= htmlspecialchars(Url::to('/clientes/rascunho/limpar'), ENT_QUOTES, 'UTF-8'); ?>"
    data-contract-commercial-config="<?= htmlspecialchars(json_encode($commercial, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}', ENT_QUOTES, 'UTF-8'); ?>"
>
    <input type="hidden" name="draft_id" value="<?= htmlspecialchars($draftId, ENT_QUOTES, 'UTF-8'); ?>">
    <input type="hidden" name="checkpoint_token" value="<?= htmlspecialchars($checkpointToken, ENT_QUOTES, 'UTF-8'); ?>">

    <section class="card">
        <div class="section-heading">
            <p class="section-heading__eyebrow">Dados principais</p>
            <h2>Identificacao do cliente</h2>
        </div>

        <div class="form-grid">
            <label class="field field--span-2">
                <span>Nome completo</span>
                <input type="text" name="nome_completo" placeholder="Nome do titular" value="<?= $fieldValue('nome_completo'); ?>" required>
            </label>

            <label class="field field--span-2">
                <span>Nome resumido</span>
                <input type="text" name="nome_resumido" placeholder="Apelido ou nome curto" value="<?= $fieldValue('nome_resumido'); ?>">
            </label>

            <label class="field">
                <span>E-mail</span>
                <input type="email" name="email" placeholder="cliente@ievo.com.br" value="<?= $fieldValue('email', ''); ?>" data-email-input>
                <small class="field-help" data-live-feedback="email">Opcional. Se preencher, o formato sera validado automaticamente.</small>
            </label>

            <label class="field">
                <span>CPF/CNPJ</span>
                <input type="text" name="cpf_cnpj" placeholder="Somente numeros ou com pontuacao" value="<?= $fieldValue('cpf_cnpj'); ?>" data-cpf-input required>
                <small class="field-help" data-live-feedback="cpf">Digite 11 digitos para CPF ou 14 para CNPJ.</small>
            </label>

            <label class="field">
                <span>Celular</span>
                <input type="text" name="celular" placeholder="(xx) x xxxx-xxxx" inputmode="tel" autocomplete="tel" value="<?= $fieldValue('celular'); ?>" data-phone-input required>
                <small class="field-help" data-live-feedback="phone">Use DDD + numero. O sistema vai formatar automaticamente.</small>
            </label>

            <label class="field">
                <span>Imprime Carne</span>
                <select name="tags_imprime">
                    <option value="nao" <?= $fieldSelected('tags_imprime', 'nao', 'nao'); ?>>Nao</option>
                    <option value="sim" <?= $fieldSelected('tags_imprime', 'sim'); ?>>Sim</option>
                </select>
                <small class="field-help">Quando marcar Sim, o sistema aplica a tag <strong>imprime</strong> e troca o tipo de cobrança para <strong>carne</strong>.</small>
            </label>

            <label class="field">
                <span>Login</span>
                <input type="text" name="login" placeholder="primeiroNome_local" value="<?= $fieldValue('login'); ?>" data-login-input required>
                <small class="field-help" data-live-feedback="login">O sistema vai normalizar para minusculas, sem acento e sem espacos.</small>
            </label>

            <label class="field">
                <span>Senha</span>
                <input type="password" name="senha" value="<?= htmlspecialchars((string) ($form['senha'] ?? $defaultPassword), ENT_QUOTES, 'UTF-8'); ?>" readonly>
            </label>
        </div>
    </section>

    <section class="card">
        <div class="section-heading">
            <p class="section-heading__eyebrow">Instalação</p>
            <h2>Endereço e observação</h2>
        </div>

        <div class="form-grid">
            <label class="field">
                <span>Tipo de instalação</span>
                <select name="tipo_instalacao" data-install-type-select required>
                    <option value="fibra" <?= $fieldSelected('tipo_instalacao', 'fibra', (string) ($defaultInstallType ?? 'fibra')); ?>>Fibra</option>
                    <option value="radio" <?= $fieldSelected('tipo_instalacao', 'radio', (string) ($defaultInstallType ?? 'fibra')); ?>>Rádio</option>
                </select>
            </label>

            <label class="field">
                <span>Local DICI</span>
                <select name="local_dici" data-local-dici-select required>
                    <option value="u" <?= $fieldSelected('local_dici', 'u', (string) ($defaultLocalDici ?? 'r')); ?>>Urbano</option>
                    <option value="r" <?= $fieldSelected('local_dici', 'r', (string) ($defaultLocalDici ?? 'r')); ?>>Rural</option>
                </select>
            </label>

            <label class="field">
                <span>Plano</span>
                <select name="plano" data-plan-select required>
                    <option value="">Selecione um plano</option>
                    <?php foreach ($plans as $plan): ?>
                        <?php
                            $planLabel = trim((string) ($plan['label'] ?? ''));
                            if ($planLabel === '' || stripos($planLabel, 'Selecione tipo de instalação e local DICI') !== false || stripos($planLabel, 'Selecione tipo de instalacao e local DICI') !== false) {
                                continue;
                            }
                        ?>
                        <option
                            value="<?= htmlspecialchars((string) $plan['id'], ENT_QUOTES, 'UTF-8'); ?>"
                            data-install-type="<?= htmlspecialchars((string) ($plan['install_type'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                            data-local-dici="<?= htmlspecialchars((string) ($plan['local_dici'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                            <?= $fieldSelected('plano', (string) $plan['id']); ?>
                        >
                            <?= htmlspecialchars($planLabel, ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <small class="field-help" data-plan-help>Mostrando apenas planos ativos e compatíveis com tipo de instalação e Local DICI.</small>
            </label>

            <label class="field">
                <span>Vencimento</span>
                <select name="vencimento" required>
                    <?php foreach ($dueDays as $dueDay): ?>
                        <?php $day = str_pad((string) ($dueDay['day'] ?? ''), 2, '0', STR_PAD_LEFT); ?>
                        <option value="<?= htmlspecialchars($day, ENT_QUOTES, 'UTF-8'); ?>" <?= $fieldSelected('vencimento', $day, (string) ($defaultDueDay ?? '05')); ?>>
                            Dia <?= htmlspecialchars($day, ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <small class="field-help">Escolha um dos dias disponíveis para vencimento.</small>
            </label>

            <label class="field">
                <span>CEP</span>
                <input type="text" name="cep" placeholder="00000-000" data-cep-input value="<?= $fieldValue('cep'); ?>">
                <small class="field-help">Ao sair do campo, o sistema tenta preencher cidade, estado e IBGE.</small>
            </label>

            <label class="field">
                <span>Cidade</span>
                <select name="cidade" data-city-select>
                    <option value="">Selecione</option>
                    <?php if ($formCity !== '' && !$cityExists): ?>
                        <option
                            value="<?= htmlspecialchars($formCity, ENT_QUOTES, 'UTF-8'); ?>"
                            data-uf="<?= htmlspecialchars((string) ($form['estado'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                            data-ibge="<?= htmlspecialchars((string) ($form['codigo_ibge'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                            selected
                        >
                            <?= htmlspecialchars($formCity, ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                    <?php endif; ?>
                    <?php foreach ($cities as $city): ?>
                        <option
                            value="<?= htmlspecialchars((string) $city['name'], ENT_QUOTES, 'UTF-8'); ?>"
                            data-uf="<?= htmlspecialchars((string) $city['uf'], ENT_QUOTES, 'UTF-8'); ?>"
                            data-ibge="<?= htmlspecialchars((string) $city['ibge'], ENT_QUOTES, 'UTF-8'); ?>"
                            <?= ((string) ($form['cidade'] ?? '') === (string) $city['name']) ? 'selected' : ''; ?>
                        >
                            <?= htmlspecialchars((string) $city['name'], ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label class="field">
                <span>Estado</span>
                <input type="text" name="estado" data-city-state readonly value="<?= $fieldValue('estado'); ?>">
            </label>

            <label class="field">
                <span>Código IBGE</span>
                <input type="text" name="codigo_ibge" data-city-ibge readonly value="<?= $fieldValue('codigo_ibge'); ?>">
            </label>

            <label class="field field--span-2">
                <span>Endereço</span>
                <input type="text" name="endereco" placeholder="Como for mais usual na regiao" value="<?= $fieldValue('endereco'); ?>">
            </label>

            <label class="field">
                <span>Número</span>
                <input type="text" name="numero" value="<?= $fieldValue('numero', 'SN'); ?>">
            </label>

            <label class="field">
                <span>Bairro</span>
                <input type="text" name="bairro" placeholder="Bairro" value="<?= $fieldValue('bairro'); ?>">
            </label>

            <label class="field">
                <span>Complemento</span>
                <input type="text" name="complemento" placeholder="Referencia da casa" value="<?= $fieldValue('complemento'); ?>">
            </label>

            <label class="field field--span-2">
                <span>Coordenada da instalação</span>
                <div class="geo-capture" data-geo-capture>
                <input type="text" name="coordenadas" placeholder="-20.850552,-42.803886" value="<?= $fieldValue('coordenadas'); ?>" data-geo-coordinate required>
                    <input type="hidden" name="coordenadas_precisao" value="<?= $fieldValue('coordenadas_precisao'); ?>" data-geo-accuracy>
                    <input type="hidden" name="coordenadas_capturadas_em" value="<?= $fieldValue('coordenadas_capturadas_em'); ?>" data-geo-captured-at>
                    <button class="button button--ghost" type="button" data-geo-button>Capturar coordenada do celular</button>
                    <button class="button button--ghost" type="button" data-geo-map>Marcar no mapa</button>
                </div>
                <small class="field-help" data-geo-help>Obrigatório. O sistema tenta capturar automaticamente pelo GPS do celular; se falhar, informe a coordenada ou marque no mapa.</small>
            </label>

            <section class="card card--span-2 contract-commercial-card">
                <div class="section-heading">
                    <p class="section-heading__eyebrow">Contrato</p>
                    <h2>Adesão e Fidelidade</h2>
                </div>

                <input type="hidden" name="vencimento_primeira_parcela" value="<?= $fieldValue('vencimento_primeira_parcela'); ?>" data-adhesion-first-due-input>

                <div class="form-grid" data-contract-commercial-form>
                    <label class="field">
                        <span>Tipo de adesão</span>
                        <select name="tipo_adesao" data-adhesion-type-input>
                            <option value="cheia" <?= $fieldSelected('tipo_adesao', 'cheia', $initialAdhesionType); ?>>Cheia</option>
                            <option value="promocional" <?= $fieldSelected('tipo_adesao', 'promocional', $initialAdhesionType); ?>>Promocional</option>
                            <option value="isenta" <?= $fieldSelected('tipo_adesao', 'isenta', $initialAdhesionType); ?>>Isenta</option>
                        </select>
                        <small class="field-help">Fibra pode começar como isenta. O técnico pode ajustar antes de concluir.</small>
                    </label>

                    <label class="field">
                        <span>Valor da adesão</span>
                        <input type="text" name="valor_adesao" inputmode="decimal" placeholder="0,00" value="<?= $fieldValue('valor_adesao', $defaultAdhesionValue !== 0.0 ? number_format($defaultAdhesionValue, 2, ',', '.') : '0,00'); ?>" data-adhesion-value-input required>
                        <small class="field-help">Preenchido com o padrão comercial e ajustável no atendimento.</small>
                    </label>

                    <label class="field">
                        <span>Parcelas da adesão</span>
                        <select name="parcelas_adesao" data-adhesion-parcels-input required>
                            <?php for ($installment = 1; $installment <= $maxAdhesionInstallments; $installment += 1): ?>
                                <option value="<?= htmlspecialchars((string) $installment, ENT_QUOTES, 'UTF-8'); ?>" <?= $fieldSelected('parcelas_adesao', (string) $installment, (string) $initialAdhesionInstallments); ?>>
                                    <?= htmlspecialchars((string) $installment, ENT_QUOTES, 'UTF-8'); ?>
                                </option>
                            <?php endfor; ?>
                        </select>
                        <small class="field-help">Não ultrapassa o máximo configurado no módulo.</small>
                    </label>

                    <label class="field">
                        <span>Valor da parcela da adesão</span>
                        <input type="text" name="valor_parcela_adesao" placeholder="Calculado automaticamente" value="<?= $fieldValue('valor_parcela_adesao', $defaultParcelValue); ?>" readonly data-adhesion-parcel-value-input>
                        <small class="field-help">Atualiza sozinho conforme o valor e a quantidade de parcelas.</small>
                    </label>

                    <label class="field">
                        <span>Fidelidade</span>
                        <input type="number" name="fidelidade_meses" min="1" value="<?= $fieldValue('fidelidade_meses', (string) $initialAdhesionFidelity); ?>" data-adhesion-fidelity-input required>
                        <small class="field-help">Padrão configurado: <?= htmlspecialchars((string) ($commercial['fidelidade_meses_padrao'] ?? 12), ENT_QUOTES, 'UTF-8'); ?> meses.</small>
                    </label>

                    <label class="field">
                        <span>Autorizado por</span>
                        <input type="text" name="beneficio_concedido_por" placeholder="Quem aprovou o benefício" value="<?= htmlspecialchars($initialBenefitAuthorizer, ENT_QUOTES, 'UTF-8'); ?>" data-adhesion-authorizer-input>
                        <small class="field-help">Informe quem aprovou a condição comercial. O valor do benefício é calculado automaticamente.</small>
                    </label>

                    <input type="hidden" name="beneficio_valor" value="<?= $fieldValue('beneficio_valor', $defaultBenefitValue); ?>" data-adhesion-benefit-input>

                    <label class="field field--span-2">
                        <span>Observação da adesão</span>
                        <textarea rows="4" name="observacao_adesao" placeholder="Descreva apenas condições complementares do acordo comercial."><?= htmlspecialchars((string) ($form['observacao_adesao'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
                    </label>
                </div>
            </section>

            <label class="field field--span-2">
                <span>Fotos da instalação</span>
                <div class="photo-uploader" data-photo-uploader>
                    <div class="photo-uploader__actions">
                        <button class="button button--ghost" type="button" data-photo-pick>Adicionar imagem do dispositivo</button>
                        <button class="button button--ghost" type="button" data-photo-camera>Abrir câmera</button>
                    </div>
                    <div data-photo-inputs>
                        <input type="file" name="fotos_instalacao[]" accept="image/*" multiple data-install-photos hidden>
                        <input type="file" name="fotos_instalacao[]" accept="image/*" capture="environment" data-photo-camera-input hidden>
                    </div>
                    <div class="photo-uploader__summary">
                        <strong data-photo-count>0 fotos anexadas</strong>
                        <small class="field-help">Voce pode anexar imagens do dispositivo em lote ou abrir a câmera para tirar na hora. A lista abaixo mostra o que já foi anexado.</small>
                    </div>
                    <div class="photo-uploader__preview" data-photo-preview></div>
                </div>
            </label>

            <label class="field field--span-2">
                <span>Observação</span>
                <textarea rows="5" name="observacao" placeholder="Ligacao, referencia, observacao da casa ou detalhe de instalação."><?= htmlspecialchars((string) ($form['observacao'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
            </label>

            <div class="form-actions field--span-2">
                <button class="button" type="submit">Salvar cadastro e abrir aceite</button>
                <button class="button button--ghost" type="button" data-clear-form>Limpar campos</button>
            </div>
        </div>
    </section>
</form>

<div class="map-modal" data-map-modal hidden>
    <div class="map-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="map-modal-title">
        <div class="map-modal__header">
            <div>
                <p class="section-heading__eyebrow">Localização</p>
                <h2 id="map-modal-title">Marcar ponto da instalação</h2>
            </div>
            <button class="button button--ghost" type="button" data-map-close>Fechar</button>
        </div>

        <div class="map-modal__canvas" data-map-canvas></div>

        <div class="map-modal__footer">
            <small class="field-help" data-map-status>Clique no mapa para marcar a casa do cliente.</small>
            <div class="map-modal__actions">
                <button class="button button--ghost" type="button" data-map-use-gps>Usar GPS atual</button>
                <button class="button" type="button" data-map-confirm>Usar ponto marcado</button>
            </div>
        </div>
    </div>
</div>
<?php
$content = (string) ob_get_clean();

require __DIR__ . '/../layouts/app.php';
