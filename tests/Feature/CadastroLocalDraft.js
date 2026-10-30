// Sem navegador, banco ou integrações: executa as funções reais do app.js.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(`${__dirname}/../../public/assets/js/app.js`, 'utf8');
function loadFunction(name) {
    const match = new RegExp(`^(?:async )?function ${name}\\(`, 'm').exec(source);
    assert.ok(match, name);
    const end = source.indexOf('\n}', match.index) + 2;
    return source.slice(match.index, end);
}
const storage = new Map();
const context = vm.createContext({
    Blob,
    FormData: class { constructor(form) { this.fields = form.fields; } entries() { return this.fields; } },
    window: {localStorage: {
        getItem: (key) => storage.get(key) ?? null,
        setItem: (key, value) => storage.set(key, value),
    }},
    photoInput: null, citySelect: null, signatureInput: null, acceptanceSelect: null,
    cepInput: null, contractCommercialForm: null,
    updatePlanOptions() {}, updateGeoMapButton() {},
});
for (const name of ['safeJsonParse', 'getDraftStorageKey', 'isDraftSecurityField',
    'sanitizeLocalDraft', 'readDraftStorage', 'writeDraftStorage', 'collectFormState',
    'applyDraftToField', 'restoreDraftToForm', 'getFormUploadSizeError']) {
    vm.runInContext(loadFunction(name), context);
}
(async () => {
    const form = {dataset: {draftKey: 'client-create'}, elements: [
        {name: '_csrf', type: 'hidden', value: 'server-token'},
        {name: 'checkpoint_token', type: 'hidden', value: 'checkpoint'},
        {name: 'draft_id', type: 'hidden', value: 'server-draft'},
        {name: 'send_request_id', type: 'hidden', value: 'server-request'},
        {name: 'senha', type: 'password', value: 'credential'},
        {name: 'nome_completo', type: 'text', value: 'Exemplo sintético'},
        {name: 'assinatura_cliente', type: 'hidden', value: 'signature-image'},
    ], querySelector: () => null};
    form.elements.forEach((element) => { element.hasAttribute = () => false; });
    const payload = context.collectFormState(form);
    assert.deepEqual(Object.keys(payload), ['nome_completo', 'assinatura_cliente']);
    storage.set('client-create', JSON.stringify({
        _csrf: 'old-token', checkpoint_token: 'old-checkpoint', draft_id: 'old-draft',
        send_request_id: 'old-request', senha: 'old-password', nome_completo: 'Restaurado',
    }));
    const restored = context.readDraftStorage(form);
    assert.deepEqual(Object.keys(restored), ['nome_completo']);
    await context.restoreDraftToForm(form);
    assert.equal(form.elements[0].value, 'server-token');
    assert.equal(form.elements[1].value, 'checkpoint');
    assert.equal(form.elements[2].value, 'server-draft');
    assert.equal(form.elements[3].value, 'server-request');
    assert.equal(form.elements[4].value, 'credential');
    assert.equal(form.elements[5].value, 'Restaurado');
    context.writeDraftStorage(form, {_csrf: 'bad', api_token: 'bad', nome_completo: 'Novo'});
    assert.deepEqual(JSON.parse(storage.get('client-create')), {nome_completo: 'Novo'});
    const uploadForm = {dataset: {postMaxBytes: 8388608, uploadMaxBytes: 2097152},
        querySelector: () => null, fields: Array.from({length: 3}, () => ['fotos[]', {size: 346570}])};
    assert.equal(context.getFormUploadSizeError(uploadForm), '');
    uploadForm.fields = [['fotos[]', {size: 2097153}]];
    assert.match(context.getFormUploadSizeError(uploadForm), /limite por arquivo/);
    uploadForm.fields = Array.from({length: 5}, () => ['fotos[]', {size: 1800000}]);
    assert.match(context.getFormUploadSizeError(uploadForm), /Envio maior que o limite/);
    uploadForm.fields = [['assinatura_cliente', 'x'.repeat(8388608)]];
    assert.match(context.getFormUploadSizeError(uploadForm), /Envio maior que o limite/);
    uploadForm.dataset = {postMaxBytes: 0, uploadMaxBytes: 0};
    assert.equal(context.getFormUploadSizeError(uploadForm), '');
    uploadForm.querySelector = () => ({});
    assert.match(context.getFormUploadSizeError(uploadForm), /Aguarde/);
    console.log('OK: rascunhos antigos/novos preservam tokens do servidor e excluem credenciais.');
    console.log('OK: 3 fotos, limites por arquivo/POST, assinatura grande e compressão pendente.');
})().catch((error) => {console.error(error); process.exitCode = 1;});
