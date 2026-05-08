/**
 * JS base da interface.
 *
 * Mantemos apenas o necessario para comportamento do menu responsivo nesta
 * etapa, evitando dependencias externas e scripts excessivos.
 */
document.documentElement.classList.add('app-ready');

const sidebar = document.querySelector('[data-sidebar]');
const sidebarToggle = document.querySelector('[data-sidebar-toggle]');
const sidebarBackdrop = document.querySelector('[data-sidebar-backdrop]');

function closeSidebar() {
    document.body.classList.remove('sidebar-open');

    if (sidebarBackdrop) {
        sidebarBackdrop.hidden = true;
    }
}

function openSidebar() {
    document.body.classList.add('sidebar-open');

    if (sidebarBackdrop) {
        sidebarBackdrop.hidden = false;
    }
}

if (sidebar && sidebarToggle && sidebarBackdrop) {
    sidebarToggle.addEventListener('click', () => {
        if (document.body.classList.contains('sidebar-open')) {
            closeSidebar();
            return;
        }

        openSidebar();
    });

    sidebarBackdrop.addEventListener('click', closeSidebar);

    window.addEventListener('resize', () => {
        if (window.innerWidth > 920) {
            closeSidebar();
        }
    });
}

const citySelect = document.querySelector('[data-city-select]');
const stateInput = document.querySelector('[data-city-state]');
const ibgeInput = document.querySelector('[data-city-ibge]');
const cepInput = document.querySelector('[data-cep-input]');
const cepHelp = cepInput ? cepInput.closest('.field')?.querySelector('.field-help') : null;
const appBasePath = (document.body?.dataset?.basePath || '').replace(/\/$/, '');
let cepLookupInFlight = false;
const signaturePad = document.querySelector('[data-signature-pad]');
const signatureCanvas = document.querySelector('[data-signature-canvas]');
const signatureInput = document.querySelector('[data-signature-input]');
const signatureClearButton = document.querySelector('[data-signature-clear]');
const signatureHelp = document.querySelector('[data-signature-help]');
const acceptanceSelect = document.querySelector('[data-acceptance-select]');
const photoInput = document.querySelector('[data-install-photos]');
const photoPickButton = document.querySelector('[data-photo-pick]');
const photoCameraButton = document.querySelector('[data-photo-camera]');
const photoCameraInput = document.querySelector('[data-photo-camera-input]');
const photoInputContainer = document.querySelector('[data-photo-inputs]');
const photoPreview = document.querySelector('[data-photo-preview]');
const photoCount = document.querySelector('[data-photo-count]');
const photoUploader = document.querySelector('[data-photo-uploader]');
const acceptanceForm = document.querySelector('[data-acceptance-form]');
const acceptanceDocumentInput = document.querySelector('[data-acceptance-document-input]');
const acceptanceDocumentHelp = acceptanceDocumentInput ? acceptanceDocumentInput.closest('.field')?.querySelector('[data-acceptance-document-help]') : null;
const cpfInput = document.querySelector('[data-cpf-input]');
const loginInput = document.querySelector('[data-login-input]');
const systemLoginInput = document.querySelector('[data-system-login-input]');
const geoButton = document.querySelector('[data-geo-button]');
const geoMapButton = document.querySelector('[data-geo-map]');
const geoCoordinateInput = document.querySelector('[data-geo-coordinate]');
const geoAccuracyInput = document.querySelector('[data-geo-accuracy]');
const geoCapturedAtInput = document.querySelector('[data-geo-captured-at]');
const geoHelp = document.querySelector('[data-geo-help]');
const mapModal = document.querySelector('[data-map-modal]');
const mapCanvas = document.querySelector('[data-map-canvas]');
const mapCloseButton = document.querySelector('[data-map-close]');
const mapConfirmButton = document.querySelector('[data-map-confirm]');
const mapUseGpsButton = document.querySelector('[data-map-use-gps]');
const mapStatus = document.querySelector('[data-map-status]');
const installationTypeSelect = document.querySelector('[data-install-type-select]');
const localDiciSelect = document.querySelector('[data-local-dici-select]');
const planSelect = document.querySelector('[data-plan-select]');
const planHelp = document.querySelector('[data-plan-help]');
const contractCommercialForm = document.querySelector('[data-contract-commercial-form]');
let signatureContext = null;
let isDrawing = false;
let hasSignatureStroke = false;
let lastPoint = null;
let activePointerId = null;
let autosaveTimer = null;
let autoGeoRequested = false;
let mapInstance = null;
let mapMarker = null;
let pendingMapCoordinate = null;
const remoteValidationTimers = new Map();
const photoCompressionConfig = {
    maxWidth: 1280,
    maxHeight: 1280,
    quality: 0.72,
};

function safeJsonParse(value, fallback = null) {
    try {
        return JSON.parse(value);
    } catch (error) {
        return fallback;
    }
}

function getDraftStorageKey(form) {
    return form?.dataset?.draftKey || '';
}

function readDraftStorage(form) {
    const key = getDraftStorageKey(form);

    if (!key) {
        return null;
    }

    try {
        const stored = window.localStorage.getItem(key);
        return stored ? safeJsonParse(stored, null) : null;
    } catch (error) {
        return null;
    }
}

function writeDraftStorage(form, payload) {
    const key = getDraftStorageKey(form);

    if (!key) {
        return;
    }

    try {
        window.localStorage.setItem(key, JSON.stringify(payload));
    } catch (error) {
        // Se a quota do navegador estourar, o formulario continua funcionando
        // com a sessao do servidor como fallback.
    }
}

function clearDraftStorageKeys(keys) {
    if (!Array.isArray(keys)) {
        return;
    }

    for (const key of keys) {
        if (!key) {
            continue;
        }

        try {
            window.localStorage.removeItem(String(key));
        } catch (error) {
            // Ignorado de proposito.
        }
    }
}

function dataUrlToFile(dataUrl, fileName = 'arquivo.jpg') {
    const parts = String(dataUrl || '').split(',');
    const header = parts[0] || '';
    const base64 = parts[1] || '';
    const mimeMatch = header.match(/data:(.*?);base64/);
    const mimeType = mimeMatch ? mimeMatch[1] : 'image/jpeg';
    const binary = atob(base64);
    const bytes = new Uint8Array(binary.length);

    for (let i = 0; i < binary.length; i += 1) {
        bytes[i] = binary.charCodeAt(i);
    }

    return new File([bytes], fileName, { type: mimeType });
}

function createImageFromFile(file) {
    return new Promise((resolve, reject) => {
        const objectUrl = URL.createObjectURL(file);
        const image = new Image();

        image.onload = () => {
            URL.revokeObjectURL(objectUrl);
            resolve(image);
        };

        image.onerror = (error) => {
            URL.revokeObjectURL(objectUrl);
            reject(error);
        };

        image.src = objectUrl;
    });
}

async function compressPhotoFile(file) {
    if (!(file instanceof File) || !String(file.type || '').startsWith('image/')) {
        return file;
    }

    const image = await createImageFromFile(file);
    const width = image.naturalWidth || image.width || 0;
    const height = image.naturalHeight || image.height || 0;

    if (width === 0 || height === 0) {
        return file;
    }

    const ratio = Math.min(
        photoCompressionConfig.maxWidth / width,
        photoCompressionConfig.maxHeight / height,
        1
    );

    if (ratio >= 1 && file.size <= 1500000) {
        return file;
    }

    const targetWidth = Math.max(1, Math.round(width * ratio));
    const targetHeight = Math.max(1, Math.round(height * ratio));
    const canvas = document.createElement('canvas');
    canvas.width = targetWidth;
    canvas.height = targetHeight;

    const context = canvas.getContext('2d');
    if (!context) {
        return file;
    }

    context.drawImage(image, 0, 0, targetWidth, targetHeight);

    const blob = await new Promise((resolve) => {
        canvas.toBlob((result) => resolve(result), 'image/jpeg', photoCompressionConfig.quality);
    });

    if (!blob) {
        return file;
    }

    const baseName = file.name.replace(/\.[^.]+$/, '') || 'foto';
    const newName = `${baseName}.jpg`;

    return new File([blob], newName, {
        type: 'image/jpeg',
        lastModified: file.lastModified || Date.now(),
    });
}

async function compressPhotoFiles(files) {
    const compressed = [];

    for (const file of files) {
        try {
            compressed.push(await compressPhotoFile(file));
        } catch (error) {
            compressed.push(file);
        }
    }

    return compressed;
}

function replaceFileInputFiles(input, files) {
    const transfer = createDataTransfer();

    if (!transfer) {
        return;
    }

    for (const file of files) {
        if (file instanceof File) {
            transfer.items.add(file);
        }
    }

    input.files = transfer.files;
}

async function hydrateDraftFiles(form, payload) {
    if (!payload || !payload.__files || !photoInput) {
        return;
    }

    const files = payload.__files[photoInput.name];

    if (!Array.isArray(files) || files.length === 0) {
        return;
    }

    try {
        const transfer = createDataTransfer();

        if (!transfer) {
            return;
        }

        for (const item of files) {
            if (!item || !item.dataUrl) {
                continue;
            }

            transfer.items.add(dataUrlToFile(item.dataUrl, item.name || 'foto.jpg'));
        }

        photoInput.files = transfer.files;
        refreshPhotoQueue();
    } catch (error) {
        // Se o navegador nao permitir restaurar os arquivos, o tecnico pode selecionar novamente.
    }
}

function createDataTransfer() {
    try {
        return new DataTransfer();
    } catch (error) {
        return null;
    }
}

function getPhotoInputs() {
    if (!photoInputContainer) {
        return [];
    }

    return Array.from(photoInputContainer.querySelectorAll('input[type="file"][name="fotos_instalacao[]"]'));
}

function getPhotoItems() {
    const items = [];

    getPhotoInputs().forEach((input) => {
        Array.from(input.files || []).forEach((file, index) => {
            items.push({ input, file, index });
        });
    });

    return items;
}

function refreshPhotoQueue() {
    const items = getPhotoItems();

    if (photoCount) {
        photoCount.textContent = `${items.length} foto${items.length === 1 ? '' : 's'} anexada${items.length === 1 ? '' : 's'}`;
        photoCount.dataset.tone = items.length > 0 ? 'success' : 'neutral';
    }

    if (photoUploader) {
        photoUploader.classList.remove('is-invalid');
    }

    if (photoPreview) {
        photoPreview.innerHTML = '';

        items.forEach((itemData, previewIndex) => {
            const { input, file, index } = itemData;
            const item = document.createElement('div');
            item.className = 'photo-chip';

            const thumb = document.createElement('div');
            thumb.className = 'photo-chip__thumb';

            const image = document.createElement('img');
            image.alt = file.name || `Foto ${previewIndex + 1}`;
            image.loading = 'lazy';
            image.src = URL.createObjectURL(file);
            image.addEventListener('load', () => {
                URL.revokeObjectURL(image.src);
            }, { once: true });
            thumb.appendChild(image);

            const meta = document.createElement('div');
            meta.className = 'photo-chip__meta';
            meta.innerHTML = `<strong>${file.name}</strong><span>${Math.ceil(file.size / 1024)} KB</span>`;

            const removeButton = document.createElement('button');
            removeButton.type = 'button';
            removeButton.className = 'photo-chip__remove';
            removeButton.textContent = 'Remover';
            removeButton.addEventListener('click', () => {
                const files = Array.from(input.files || []);
                const transfer = createDataTransfer();

                if (files.length <= 1 || !transfer) {
                    input.remove();
                } else {
                    files.forEach((currentFile, currentIndex) => {
                        if (currentIndex !== index) {
                            transfer.items.add(currentFile);
                        }
                    });

                    try {
                        input.files = transfer.files;
                    } catch (error) {
                        input.remove();
                    }
                }

                refreshPhotoQueue();
                if (photoInputContainer?.closest('form')) {
                    scheduleDraftSave(photoInputContainer.closest('form'));
                }
            });

            const content = document.createElement('div');
            content.className = 'photo-chip__content';
            content.appendChild(meta);

            item.appendChild(thumb);
            item.appendChild(content);
            item.appendChild(removeButton);
            photoPreview.appendChild(item);
        });
    }
}

function wirePhotoPicker(button, input, multiple = true) {
    if (!button || !input) {
        return;
    }

    let activeInput = input;

    const attachChangeHandler = (fileInput) => {
        fileInput.addEventListener('change', () => {
            if (!fileInput.files || fileInput.files.length === 0) {
                return;
            }

            const selectedFiles = Array.from(fileInput.files);
            const nextInput = fileInput.cloneNode(false);
            nextInput.value = '';
            nextInput.multiple = Boolean(multiple);
            fileInput.insertAdjacentElement('afterend', nextInput);
            activeInput = nextInput;
            attachChangeHandler(nextInput);

            void (async () => {
                const compressedFiles = await compressPhotoFiles(selectedFiles);
                replaceFileInputFiles(fileInput, compressedFiles);
                refreshPhotoQueue();

                if (fileInput.form) {
                    scheduleDraftSave(fileInput.form);
                }
            })();
        });
    };

    attachChangeHandler(activeInput);

    button.addEventListener('click', () => {
        activeInput.multiple = Boolean(multiple);
        activeInput.click();
    });
}

function collectFormState(form) {
    const state = {};

    Array.from(form.elements).forEach((element) => {
        if (!element.name || element.disabled) {
            return;
        }

        if (element.type === 'file') {
            return;
        }

        if (element.type === 'checkbox') {
            state[element.name] = element.checked;
            return;
        }

        if (element.type === 'radio') {
            if (element.checked) {
                state[element.name] = element.value;
            }
            return;
        }

        if (element.tagName === 'SELECT' && element.multiple) {
            state[element.name] = Array.from(element.selectedOptions).map((option) => option.value);
            return;
        }

        state[element.name] = element.value;
    });

    return state;
}

async function collectFileState(form) {
    const fileInputs = Array.from(form.querySelectorAll('input[type="file"]'));

    if (fileInputs.length === 0) {
        return {};
    }

    const filesState = {};

    for (const input of fileInputs) {
        if (!input.name || !input.files || input.files.length === 0) {
            continue;
        }

        const items = [];

        for (const file of Array.from(input.files)) {
            const dataUrl = await new Promise((resolve, reject) => {
                const reader = new FileReader();
                reader.onload = () => resolve(String(reader.result || ''));
                reader.onerror = () => reject(reader.error);
                reader.readAsDataURL(file);
            });

            items.push({
                name: file.name,
                type: file.type,
                size: file.size,
                dataUrl,
            });
        }

        filesState[input.name] = [
            ...(filesState[input.name] || []),
            ...items,
        ];
    }

    return filesState;
}

async function saveDraftFromForm(form) {
    const payload = collectFormState(form);
    const files = await collectFileState(form);

    if (Object.keys(files).length > 0) {
        payload.__files = files;
    }

    writeDraftStorage(form, payload);
}

function clearDraftStorageAndServer(form) {
    const key = getDraftStorageKey(form);
    if (key) {
        try {
            window.localStorage.removeItem(key);
        } catch (error) {
            // Ignorado.
        }
    }

    const endpoint = form?.dataset?.clearDraftEndpoint || '';

    if (endpoint && key) {
        const body = new URLSearchParams({ key });
        getPhotoInputs().forEach((input) => {
            if ((input.files || []).length > 0) {
                input.remove();
            } else {
                input.value = '';
            }
        });
        refreshPhotoQueue();
        return fetch(buildAppUrl(endpoint), {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
            },
            body,
            credentials: 'same-origin',
            keepalive: true,
        }).catch(() => {});
    }

    return Promise.resolve();
}

function applyDraftToField(element, value) {
    if (element.type === 'checkbox') {
        element.checked = Boolean(value);
        return;
    }

    if (element.type === 'radio') {
        element.checked = element.value === value;
        return;
    }

    if (element.tagName === 'SELECT' && element.multiple && Array.isArray(value)) {
        Array.from(element.options).forEach((option) => {
            option.selected = value.includes(option.value);
        });
        return;
    }

    element.value = value ?? '';
}

async function restoreDraftToForm(form) {
    if (form?.dataset?.skipDraftRestore === '1') {
        return;
    }

    const payload = readDraftStorage(form) || safeJsonParse(form.dataset.draftJson || '', null) || null;

    if (!payload) {
        return;
    }

    Array.from(form.elements).forEach((element) => {
        if (!element.name || element.disabled || element.type === 'file') {
            return;
        }

        if (element.hasAttribute('data-skip-draft-restore')) {
            return;
        }

        if (Object.prototype.hasOwnProperty.call(payload, element.name)) {
            applyDraftToField(element, payload[element.name]);
        }
    });

    if (photoInput) {
        await hydrateDraftFiles(form, payload);
    }

    if (citySelect) {
        syncCityMetadata();
    }

    updatePlanOptions();
    updateGeoMapButton();

    if (signatureInput && payload.assinatura_cliente) {
        signatureInput.value = payload.assinatura_cliente;
    }

    if (acceptanceSelect) {
        updateAcceptanceVisibility();
    }

    if (typeof payload.cep === 'string' && cepInput) {
        setCepHelp(payload.cep.replace(/\D+/g, '').length === 8 ? 'CEP restaurado do rascunho.' : 'CEP restaurado do rascunho.', 'neutral');
    }

    if (typeof payload.login === 'string') {
        const loginInput = form.querySelector('[data-login-input]');
        if (loginInput) {
            loginInput.value = payload.login;
        }
    }

    if (contractCommercialForm) {
        updateCommercialSection(true);
    }
}

function scheduleDraftSave(form) {
    if (autosaveTimer) {
        window.clearTimeout(autosaveTimer);
    }

    autosaveTimer = window.setTimeout(() => {
        saveDraftFromForm(form).catch(() => {});
    }, 150);
}

function wireAutosave(form) {
    if (!form) {
        return;
    }

    const clearKeys = safeJsonParse(form.dataset.clearDraftKeys || '[]', []);
    clearDraftStorageKeys(clearKeys);

    restoreDraftToForm(form)
        .then(() => {
            updatePlanOptions();
            maybeAutoCaptureGeolocation();
        })
        .catch(() => {});

    form.addEventListener('input', () => scheduleDraftSave(form));
    form.addEventListener('change', async () => {
        scheduleDraftSave(form);

        if (form.dataset.draftKey && form.dataset.draftKey.startsWith('client-acceptance')) {
            if (photoInput && photoInput.files && photoInput.files.length > 0) {
                await saveDraftFromForm(form);
            }
        }
    });

    const clearButton = form.querySelector('[data-clear-form]');

    if (clearButton) {
        clearButton.addEventListener('click', async () => {
            await clearDraftStorageAndServer(form);
            window.location.href = buildAppUrl('/clientes/novo');
        });
    }

    form.addEventListener('submit', () => {
        saveDraftFromForm(form).catch(() => {});
    });

    const focusFieldOnSubmit = (field) => {
        if (!field) {
            return;
        }

        if (typeof field.focus === 'function') {
            field.focus({ preventScroll: true });
        }

        if (typeof field.scrollIntoView === 'function') {
            field.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    };

    form.addEventListener('submit', (event) => {
        if (typeof form.checkValidity === 'function' && !form.checkValidity()) {
            event.preventDefault();

            const invalidFields = Array.from(form.querySelectorAll(':invalid'));
            invalidFields.forEach((field) => field.setAttribute('aria-invalid', 'true'));
            const firstInvalid = invalidFields[0] || null;
            if (firstInvalid) {
                focusFieldOnSubmit(firstInvalid);
            }

            if (typeof form.reportValidity === 'function') {
                form.reportValidity();
            }
            return;
        }

        if (photoUploader) {
            const hasPhotos = getPhotoItems().length > 0;
            if (!hasPhotos) {
                event.preventDefault();
                photoUploader.classList.add('is-invalid');
                if (photoCount) {
                    photoCount.textContent = 'Envie ao menos uma foto da instalacao.';
                    photoCount.dataset.tone = 'error';
                }
                focusFieldOnSubmit(photoUploader);
            }
        }
    }, true);
}

function buildAppUrl(path) {
    const normalizedPath = `/${String(path || '').replace(/^\/+/, '')}`;

    if (!appBasePath) {
        return normalizedPath;
    }

    return `${appBasePath}${normalizedPath}`;
}

function normalizeLoginValue(value) {
    return (value || '')
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .toLowerCase()
        .replace(/[^a-z0-9_-]+/g, '_')
        .replace(/^_+|_+$/g, '');
}

function formatPhoneValue(value) {
    const digits = String(value || '').replace(/\D+/g, '').slice(0, 11);

    if (digits.length === 0) {
        return '';
    }

    const ddd = digits.slice(0, 2);
    const rest = digits.slice(2);

    if (digits.length <= 2) {
        return `(${ddd}`;
    }

    if (digits.length <= 6) {
        return `(${ddd}) ${rest}`;
    }

    if (digits.length <= 10) {
        return `(${ddd}) ${rest.slice(0, 4)}-${rest.slice(4)}`;
    }

    return `(${ddd}) ${rest.slice(0, 1)} ${rest.slice(1, 5)}-${rest.slice(5)}`;
}

function validateCpfValue(value, { strict = false } = {}) {
    const digits = String(value || '').replace(/\D+/g, '');

    if (digits.length === 0) {
        return strict
            ? { valid: false, message: 'Informe um CPF ou CNPJ valido.' }
            : { valid: true, message: '' };
    }

    if (digits.length < 11 && digits.length !== 14) {
        return strict
            ? { valid: false, message: 'CPF deve ter 11 digitos ou CNPJ deve ter 14 digitos.' }
            : { valid: true, message: '' };
    }

    if (digits.length === 11) {
        if (/^(\d)\1{10}$/.test(digits)) {
            return { valid: false, message: 'CPF invalido.' };
        }

        for (let t = 9; t < 11; t += 1) {
            let sum = 0;
            for (let i = 0; i < t; i += 1) {
                sum += parseInt(digits[i], 10) * ((t + 1) - i);
            }
            const digit = ((10 * sum) % 11) % 10;
            if (parseInt(digits[t], 10) !== digit) {
                return { valid: false, message: 'CPF invalido.' };
            }
        }

        return { valid: true, message: '' };
    }

    if (digits.length === 14) {
        if (/^(\d)\1{13}$/.test(digits)) {
            return { valid: false, message: 'CNPJ invalido.' };
        }

        const calcDigit = (base) => {
            let length = base.length;
            let pos = length - 7;
            let sum = 0;

            for (let i = length; i >= 1; i -= 1) {
                sum += parseInt(base[length - i], 10) * pos--;
                if (pos < 2) {
                    pos = 9;
                }
            }

            return sum % 11 < 2 ? 0 : 11 - (sum % 11);
        };

        const first = calcDigit(digits.slice(0, 12));
        if (parseInt(digits[12], 10) !== first) {
            return { valid: false, message: 'CNPJ invalido.' };
        }

        const second = calcDigit(digits.slice(0, 13));
        if (parseInt(digits[13], 10) !== second) {
            return { valid: false, message: 'CNPJ invalido.' };
        }

        return { valid: true, message: '' };
    }

    return strict
        ? { valid: false, message: 'CPF deve ter 11 digitos ou CNPJ deve ter 14 digitos.' }
        : { valid: true, message: '' };
}

function validatePhoneValue(value, { strict = false } = {}) {
    const digits = String(value || '').replace(/\D+/g, '');

    if (digits.length === 0) {
        return strict
            ? { valid: false, message: 'Informe um telefone com DDD.' }
            : { valid: true, message: '' };
    }

    return {
        valid: digits.length === 10 || digits.length === 11,
        message: 'Telefone invalido. Use DDD e 10 ou 11 digitos.',
    };
}

function validateEmailValue(value) {
    if (!String(value || '').trim()) {
        return { valid: true, message: '' };
    }

    return {
        valid: /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(String(value).trim()),
        message: 'E-mail invalido.',
    };
}

function applyLiveValidation(input, validator, normalizer = null, options = {}) {
    if (!input) {
        return;
    }

    const field = input.closest('.field');
    const feedback = field ? field.querySelector('[data-live-feedback]') : null;
    const defaultFeedback = feedback ? feedback.textContent.trim() : '';
    const settings = {
        validateOnInput: true,
        validateOnBlur: true,
        ...options,
    };

    const setFeedback = (message, tone = 'neutral') => {
        if (!feedback) {
            return;
        }

        feedback.textContent = message;
        feedback.dataset.tone = tone;
    };

    const runValidation = (strict = false) => {
        if (typeof normalizer === 'function') {
            const normalized = normalizer(input.value);
            if (input.value !== normalized) {
                input.value = normalized;
            }
        }

        const result = validator(input.value, { strict });

        if (result.valid) {
            input.setCustomValidity('');
            input.removeAttribute('aria-invalid');
            setFeedback(defaultFeedback, 'neutral');
            return;
        }

        input.setCustomValidity(result.message);
        input.setAttribute('aria-invalid', 'true');
        setFeedback(result.message, 'error');
    };

    if (settings.validateOnInput) {
        input.addEventListener('input', runValidation);
    }

    if (settings.validateOnBlur) {
        input.addEventListener('blur', () => {
            runValidation(true);
        });
    }

    runValidation(false);
}

function setRemoteFieldMessage(input, feedback, message, tone = 'neutral') {
    if (!feedback) {
        return;
    }

    feedback.textContent = message;
    feedback.dataset.tone = tone;

    if (!input) {
        return;
    }

    if (tone === 'error') {
        input.setCustomValidity(message);
        input.setAttribute('aria-invalid', 'true');
        return;
    }

    if (input.validationMessage === message || input.validationMessage === '') {
        input.setCustomValidity('');
        input.removeAttribute('aria-invalid');
    }
}

async function validateRemoteClientField(type, input, feedback, normalizer, minLength = 1, endpointPath = '/api/cliente/validar', expectedExists = false) {
    if (!input) {
        return;
    }

    const normalized = typeof normalizer === 'function' ? normalizer(input.value) : String(input.value || '');
    const value = normalized.trim();

    if (value.length < minLength) {
        return;
    }

    const localValidity = input.checkValidity();
    if (!localValidity) {
        return;
    }

    const url = buildAppUrl(`${endpointPath}?type=${encodeURIComponent(type)}&value=${encodeURIComponent(value)}`);

    setRemoteFieldMessage(input, feedback, 'Consultando MkAuth...', 'loading');

    try {
        const response = await fetch(url, {
            headers: { 'Accept': 'application/json' },
            credentials: 'same-origin',
        });

        const payload = await response.json();

        if (!response.ok || payload.status !== 'success') {
            setRemoteFieldMessage(input, feedback, 'Nao foi possivel consultar agora. Tente novamente.', 'warning');
            return;
        }

        if (Boolean(payload.exists) !== Boolean(expectedExists)) {
            setRemoteFieldMessage(
                input,
                feedback,
                payload.message || (expectedExists ? 'Usuario nao encontrado.' : 'Registro ja existe.'),
                'error'
            );
            return;
        }

        setRemoteFieldMessage(input, feedback, payload.message || 'Disponivel para uso.', 'success');
    } catch (error) {
        setRemoteFieldMessage(input, feedback, 'Nao foi possivel consultar agora. Tente novamente.', 'warning');
    }
}

function scheduleRemoteClientValidation(type, input, feedback, normalizer, minLength, delayMs = 450, endpointPath = '/api/cliente/validar', expectedExists = false) {
    if (!input) {
        return;
    }

    const key = `${type}:${input.name || type}`;
    const previous = remoteValidationTimers.get(key);

    if (previous) {
        window.clearTimeout(previous);
    }

    const timer = window.setTimeout(() => {
        validateRemoteClientField(type, input, feedback, normalizer, minLength, endpointPath, expectedExists).catch(() => {});
    }, delayMs);

    remoteValidationTimers.set(key, timer);
}

function syncCityMetadata() {
    if (!citySelect || !stateInput || !ibgeInput) {
        return;
    }

    const selected = citySelect.selectedOptions[0];
    const uf = selected ? selected.dataset.uf || '' : '';
    const ibge = selected ? selected.dataset.ibge || '' : '';

    stateInput.value = uf;
    ibgeInput.value = ibge;
}

if (citySelect && stateInput && ibgeInput) {
    citySelect.addEventListener('change', syncCityMetadata);
    syncCityMetadata();
}

function updatePlanOptions() {
    if (!planSelect || !installationTypeSelect || !localDiciSelect) {
        return;
    }

    const selectedType = String(installationTypeSelect.value || '').toLowerCase();
    const selectedLocal = String(localDiciSelect.value || '').toLowerCase();
    let visibleCount = 0;
    let selectedStillVisible = false;

    Array.from(planSelect.options).forEach((option) => {
        const optionLabel = String(option.textContent || '').trim().toLowerCase();
        const looksLikePlaceholder = optionLabel.includes('selecione tipo de instalação e local dici')
            || optionLabel.includes('selecione tipo de instalacao e local dici');

        if (!option.value || looksLikePlaceholder) {
            option.hidden = false;
            option.disabled = !option.value || looksLikePlaceholder;
            if (looksLikePlaceholder && option.selected) {
                option.selected = false;
            }
            return;
        }

        const matches = option.dataset.installType === selectedType
            && option.dataset.localDici === selectedLocal;

        option.hidden = !matches;
        option.disabled = !matches;

        if (matches) {
            visibleCount += 1;
            if (option.selected) {
                selectedStillVisible = true;
            }
        }
    });

    if (!selectedStillVisible) {
        planSelect.value = '';
    }

    const typeLabel = selectedType === 'radio' ? 'radio' : 'fibra';
    const localLabel = selectedLocal === 'r' ? 'rural' : 'urbano';

    if (planHelp) {
        planHelp.textContent = visibleCount > 0
            ? `${visibleCount} plano${visibleCount === 1 ? '' : 's'} disponivel${visibleCount === 1 ? '' : 'is'} para ${typeLabel} ${localLabel}.`
            : `Nenhum plano ativo encontrado para ${typeLabel} ${localLabel}. Verifique os planos no MkAuth.`;
        planHelp.dataset.tone = visibleCount > 0 ? 'success' : 'warning';
    }
}

if (installationTypeSelect) {
    installationTypeSelect.addEventListener('change', updatePlanOptions);
}

if (localDiciSelect) {
    localDiciSelect.addEventListener('change', updatePlanOptions);
}

function parseMoneyFieldValue(value) {
    const text = String(value || '').trim();

    if (!text) {
        return 0;
    }

    const normalized = text
        .replace(/[^\d,.-]/g, '')
        .replace(/\.(?=\d{3}(?:\D|$))/g, '')
        .replace(/,/g, '.');

    const parsed = Number(normalized);

    return Number.isFinite(parsed) ? parsed : 0;
}

function formatMoneyFieldValue(value) {
    const parsed = Number(value) || 0;

    return parsed.toLocaleString('pt-BR', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
}

function formatLocalDate(date) {
    return [
        date.getFullYear(),
        String(date.getMonth() + 1).padStart(2, '0'),
        String(date.getDate()).padStart(2, '0'),
    ].join('-');
}

function calculateNextBillingDateFromDay(dueDayValue) {
    const dueDay = Number.parseInt(String(dueDayValue || '').replace(/\D+/g, ''), 10);

    if (!Number.isFinite(dueDay) || dueDay < 1 || dueDay > 31) {
        return '';
    }

    const today = new Date();
    today.setHours(0, 0, 0, 0);
    const current = new Date(today.getFullYear(), today.getMonth(), Math.min(dueDay, new Date(today.getFullYear(), today.getMonth() + 1, 0).getDate()));
    current.setHours(0, 0, 0, 0);

    if (current >= today) {
        return formatLocalDate(current);
    }

    const nextMonth = new Date(today.getFullYear(), today.getMonth() + 1, Math.min(dueDay, new Date(today.getFullYear(), today.getMonth() + 2, 0).getDate()));
    nextMonth.setHours(0, 0, 0, 0);

    return formatLocalDate(nextMonth);
}

function readCommercialConfig() {
    if (!contractCommercialForm) {
        return null;
    }

    return safeJsonParse(contractCommercialForm.closest('form')?.dataset.contractCommercialConfig || '{}', {});
}

function markCommercialManual(input) {
    if (!input) {
        return;
    }

    if (input.matches('[data-adhesion-type-input]')) {
        return;
    }

    input.dataset.manualValue = '1';
}

function setCommercialValue(input, value, allowOverride = false) {
    if (!input) {
        return;
    }

    if (allowOverride || input.dataset.manualValue !== '1' || input.value.trim() === '') {
        input.value = value;
        input.dataset.manualValue = '0';
    }
}

function updateCommercialSection(force = false) {
    if (!contractCommercialForm) {
        return;
    }

    const config = readCommercialConfig() || {};
    const installTypeInput = contractCommercialForm.querySelector('[data-install-type-select]');
    const typeSelect = contractCommercialForm.querySelector('[data-adhesion-type-input]');
    const valueInput = contractCommercialForm.querySelector('[data-adhesion-value-input]');
    const parcelsInput = contractCommercialForm.querySelector('[data-adhesion-parcels-input]');
    const parcelValueInput = contractCommercialForm.querySelector('[data-adhesion-parcel-value-input]');
    const firstDueInput = contractCommercialForm.querySelector('[data-adhesion-first-due-input]');
    const fidelityInput = contractCommercialForm.querySelector('[data-adhesion-fidelity-input]');
    const authorizerInput = contractCommercialForm.querySelector('[data-adhesion-authorizer-input]');
    const benefitInput = contractCommercialForm.querySelector('[data-adhesion-benefit-input]');
    const dueDaySelect = contractCommercialForm.querySelector('[name="vencimento"]');

    const defaultAdhesionType = 'cheia';
    const baseValue = Number(config.valor_adesao_padrao || 0);
    const promoValue = Number(config.valor_adesao_promocional || 0);
    const discountPercent = Number(config.percentual_desconto_promocional || 0);
    const maxParcels = Math.max(1, Number(config.parcelas_maximas_adesao || 1));
    const defaultFidelity = Math.max(1, Number(config.fidelidade_meses_padrao || 12));

    if (typeSelect && !typeSelect.value) {
        typeSelect.value = defaultAdhesionType;
        typeSelect.dataset.manualValue = '0';
    }

    const currentType = String(typeSelect?.value || defaultAdhesionType);
    const previousType = String(contractCommercialForm.dataset.lastAdhesionType || '');
    const adhesionTypeChanged = previousType !== '' && previousType !== currentType;
    contractCommercialForm.dataset.lastAdhesionType = currentType;
    let adhesionValue = parseMoneyFieldValue(valueInput?.value);
    const isTypingAdhesionValue = document.activeElement === valueInput;
    const shouldReset = force || adhesionTypeChanged;

    if (shouldReset) {
        if (valueInput) {
            valueInput.dataset.manualValue = '0';
        }
        if (parcelsInput) {
            parcelsInput.dataset.manualValue = '0';
            parcelsInput.value = '1';
        }
        if (benefitInput) {
            benefitInput.dataset.manualValue = '0';
        }
    }

    const resolveConfiguredAdhesionValue = () => {
        if (currentType === 'isenta') {
            return 0;
        }

        if (currentType === 'promocional') {
            return promoValue > 0
                ? promoValue
                : Math.max(0, baseValue - (baseValue * Math.max(0, discountPercent) / 100));
        }

        return baseValue;
    };

    if (valueInput) {
        const valueLocked = currentType === 'cheia' || currentType === 'isenta';
        valueInput.readOnly = valueLocked;
        valueInput.classList.toggle('is-readonly', valueLocked);
        valueInput.setAttribute('aria-disabled', valueLocked ? 'true' : 'false');
    }

    if (shouldReset || valueInput?.dataset.manualValue !== '1' || !valueInput?.value.trim()) {
        adhesionValue = resolveConfiguredAdhesionValue();

        if (!isTypingAdhesionValue || force) {
            setCommercialValue(valueInput, formatMoneyFieldValue(adhesionValue), true);
        }
    }

    const parcelsValue = Number.parseInt(String(parcelsInput?.value || '1').replace(/\D+/g, ''), 10);
    if (parcelsInput) {
        parcelsInput.max = String(maxParcels);
        if (shouldReset || parcelsInput.dataset.manualValue !== '1' || !parcelsInput.value) {
            parcelsInput.value = '1';
            parcelsInput.dataset.manualValue = '0';
        } else if (parcelsValue > maxParcels) {
            parcelsInput.value = String(maxParcels);
        }
    }

    const currentParcels = Math.max(1, Number.parseInt(String(parcelsInput?.value || '1').replace(/\D+/g, ''), 10) || 1);
    const parcelValue = currentParcels > 0 ? (adhesionValue / currentParcels) : 0;
    if (parcelValueInput) {
        parcelValueInput.value = formatMoneyFieldValue(parcelValue);
    }

    if (fidelityInput) {
        if (force || fidelityInput.dataset.manualValue !== '1' || !fidelityInput.value) {
            fidelityInput.value = String(defaultFidelity);
            fidelityInput.dataset.manualValue = '0';
        }
    }

    if (benefitInput) {
        const benefitValue = currentType === 'isenta'
            ? baseValue
            : Math.max(0, baseValue - adhesionValue);

        if (shouldReset || benefitInput.dataset.manualValue !== '1' || !benefitInput.value.trim()) {
            setCommercialValue(benefitInput, formatMoneyFieldValue(benefitValue), true);
        }
    }

    if (authorizerInput && force && authorizerInput.value.trim() === '') {
        authorizerInput.value = '';
        authorizerInput.dataset.manualValue = '0';
    }

    if (authorizerInput) {
        authorizerInput.required = currentType === 'promocional' || currentType === 'isenta';
        authorizerInput.closest('.field')?.classList.toggle('field--required', authorizerInput.required);
    }

    if (firstDueInput && dueDaySelect) {
        if (shouldReset || firstDueInput.dataset.manualValue !== '1' || !firstDueInput.value) {
            const computedDate = calculateNextBillingDateFromDay(dueDaySelect.value);
            if (computedDate) {
                firstDueInput.value = computedDate;
                firstDueInput.dataset.manualValue = '0';
            }
        }
    }
}

if (contractCommercialForm) {
    const commercialInputs = contractCommercialForm.querySelectorAll('input, select, textarea');

    commercialInputs.forEach((input) => {
        input.addEventListener('input', () => {
            markCommercialManual(input);
            updateCommercialSection();
        });

        input.addEventListener('change', () => {
            markCommercialManual(input);
            updateCommercialSection();
        });
    });

    const adhesionValueInput = contractCommercialForm.querySelector('[data-adhesion-value-input]');
    if (adhesionValueInput) {
        adhesionValueInput.addEventListener('blur', () => {
            const parsed = parseMoneyFieldValue(adhesionValueInput.value);
            if (adhesionValueInput.value.trim() === '') {
                adhesionValueInput.dataset.manualValue = '0';
                updateCommercialSection(true);
                return;
            }

            adhesionValueInput.value = formatMoneyFieldValue(parsed);
            adhesionValueInput.dataset.manualValue = '1';
            updateCommercialSection();
        });
    }

    const adhesionTypeInput = contractCommercialForm.querySelector('[data-adhesion-type-input]');
    if (adhesionTypeInput) {
        adhesionTypeInput.addEventListener('change', () => {
            const valueInput = contractCommercialForm.querySelector('[data-adhesion-value-input]');
            const parcelsInput = contractCommercialForm.querySelector('[data-adhesion-parcels-input]');
            const benefitInput = contractCommercialForm.querySelector('[data-adhesion-benefit-input]');
            const parcelValueInput = contractCommercialForm.querySelector('[data-adhesion-parcel-value-input]');

            if (valueInput) {
                valueInput.dataset.manualValue = '0';
                valueInput.value = '';
            }
            if (parcelsInput) {
                parcelsInput.dataset.manualValue = '0';
                parcelsInput.value = '1';
            }
            if (parcelValueInput) {
                parcelValueInput.value = '0,00';
            }
            if (benefitInput) {
                benefitInput.dataset.manualValue = '0';
            }

            updateCommercialSection(true);
        });
    }

    if (installationTypeSelect) {
        installationTypeSelect.addEventListener('change', () => {
            const typeSelect = contractCommercialForm.querySelector('[data-adhesion-type-input]');
            const valueInput = contractCommercialForm.querySelector('[data-adhesion-value-input]');
            const benefitInput = contractCommercialForm.querySelector('[data-adhesion-benefit-input]');

            if (typeSelect) {
                typeSelect.dataset.manualValue = '0';
            }
            if (valueInput) {
                valueInput.dataset.manualValue = '0';
            }
            if (benefitInput) {
                benefitInput.dataset.manualValue = '0';
            }

            updateCommercialSection(true);
        });
    }

    if (localDiciSelect) {
        localDiciSelect.addEventListener('change', () => updateCommercialSection());
    }

    const dueDaySelect = contractCommercialForm.querySelector('[name="vencimento"]');
    if (dueDaySelect) {
        dueDaySelect.addEventListener('change', () => {
            const firstDueInput = contractCommercialForm.querySelector('[data-adhesion-first-due-input]');
            if (firstDueInput && (firstDueInput.dataset.manualValue !== '1' || !firstDueInput.value)) {
                firstDueInput.value = calculateNextBillingDateFromDay(dueDaySelect.value);
                firstDueInput.dataset.manualValue = '0';
            }
        });
    }

    updateCommercialSection(true);
}

const addressNumberInput = document.querySelector('[data-address-number-input]');

if (addressNumberInput instanceof HTMLInputElement) {
    addressNumberInput.addEventListener('focus', () => {
        if (addressNumberInput.value.trim().toUpperCase() === 'SN') {
            addressNumberInput.value = '';
        }
    });

    addressNumberInput.addEventListener('blur', () => {
        if (addressNumberInput.value.trim() === '') {
            addressNumberInput.value = 'SN';
        } else {
            addressNumberInput.value = addressNumberInput.value.trim().toUpperCase();
        }
    });
}

function normalizeCep(value) {
    return (value || '').replace(/\D+/g, '').slice(0, 8);
}

function setCepHelp(text, tone = 'neutral') {
    if (!cepHelp) {
        return;
    }

    cepHelp.textContent = text;
    cepHelp.dataset.tone = tone;
}

function setupSignaturePad() {
    if (!signaturePad || !signatureCanvas || !signatureInput) {
        return;
    }

        signatureContext = signatureCanvas.getContext('2d');

    if (!signatureContext) {
        return;
    }

    const drawBlankCanvas = (width, height) => {
        signatureContext.clearRect(0, 0, width, height);
        signatureContext.fillStyle = '#ffffff';
        signatureContext.fillRect(0, 0, width, height);
    };

    const resizeCanvas = (preserveSignature = true) => {
        const ratio = Math.max(window.devicePixelRatio || 1, 1);
        const rect = signatureCanvas.getBoundingClientRect();
        const width = Math.max(Math.floor(rect.width), 300);
        const height = Math.max(Math.floor(rect.height), 200);
        const snapshot = preserveSignature
            ? (signatureInput && signatureInput.value ? signatureInput.value : signaturePad.dataset.signatureSnapshot || '')
            : '';

        signatureCanvas.width = Math.floor(width * ratio);
        signatureCanvas.height = Math.floor(height * ratio);
        signatureContext.setTransform(ratio, 0, 0, ratio, 0, 0);
        signatureContext.imageSmoothingEnabled = true;
        signatureContext.imageSmoothingQuality = 'high';
        signatureContext.lineWidth = 2.5;
        signatureContext.lineCap = 'round';
        signatureContext.lineJoin = 'round';
        signatureContext.strokeStyle = '#17324d';
        signatureContext.fillStyle = '#ffffff';
        drawBlankCanvas(width, height);

        if (snapshot) {
            renderFromSignatureValue(snapshot);
        }
    };

    const getPoint = (event) => {
        const rect = signatureCanvas.getBoundingClientRect();
        return {
            x: event.clientX - rect.left,
            y: event.clientY - rect.top,
        };
    };

    const saveSignature = () => {
        if (!signatureInput) {
            return;
        }

        if (!hasSignatureStroke) {
            signatureInput.value = '';
            delete signaturePad.dataset.signatureSnapshot;
            const form = signatureInput.closest('form');
            if (form) {
                scheduleDraftSave(form);
            }
            return;
        }

        const dataUrl = signatureCanvas.toDataURL('image/png');
        signatureInput.value = dataUrl;
        signaturePad.dataset.signatureSnapshot = dataUrl;
        const form = signatureInput.closest('form');
        if (form) {
            scheduleDraftSave(form);
        }
    };

    const renderFromSignatureValue = (value) => {
        if (!signatureContext || !value || !String(value).startsWith('data:image/')) {
            return;
        }

        const image = new Image();
        image.onload = () => {
            const rect = signatureCanvas.getBoundingClientRect();
            drawBlankCanvas(rect.width, rect.height);
            signatureContext.drawImage(image, 0, 0, rect.width, rect.height);
            if (signatureInput) {
                signaturePad.dataset.signatureSnapshot = String(value);
            }
        };
        image.src = String(value);
    };

    const drawLine = (from, to) => {
        if (!signatureContext || !from || !to) {
            return;
        }

        signatureContext.beginPath();
        signatureContext.moveTo(from.x, from.y);
        signatureContext.lineTo(to.x, to.y);
        signatureContext.stroke();
    };

    const startDrawing = (event) => {
        event.preventDefault();
        activePointerId = event.pointerId ?? null;
        if (signatureCanvas.setPointerCapture) {
            try {
                signatureCanvas.setPointerCapture(event.pointerId);
            } catch (error) {
                // Alguns navegadores nao permitem captura em cenarios especificos.
            }
        }
        isDrawing = true;
        hasSignatureStroke = true;
        lastPoint = getPoint(event);
    };

    const moveDrawing = (event) => {
        if (!isDrawing || !lastPoint) {
            return;
        }

        event.preventDefault();
        const currentPoint = getPoint(event);
        drawLine(lastPoint, currentPoint);
        lastPoint = currentPoint;
        saveSignature();
    };

    const stopDrawing = () => {
        if (!isDrawing) {
            return;
        }

        isDrawing = false;
        lastPoint = null;
        if (signatureCanvas.releasePointerCapture) {
            try {
                if (activePointerId !== null) {
                    signatureCanvas.releasePointerCapture(activePointerId);
                }
            } catch (error) {
                // Ignorado por seguranca.
            }
        }
        activePointerId = null;
        saveSignature();
    };

    resizeCanvas(false);
    window.addEventListener('resize', () => resizeCanvas(true));
    window.addEventListener('orientationchange', () => {
        window.setTimeout(() => resizeCanvas(true), 180);
    });

    signatureCanvas.addEventListener('pointerdown', startDrawing);
    signatureCanvas.addEventListener('pointermove', moveDrawing);
    signatureCanvas.addEventListener('pointerup', stopDrawing);
    signatureCanvas.addEventListener('pointercancel', stopDrawing);

    if (signatureClearButton) {
        signatureClearButton.addEventListener('click', () => {
            hasSignatureStroke = false;
            resizeCanvas(false);
            if (signatureInput) {
                signatureInput.value = '';
            }
            delete signaturePad.dataset.signatureSnapshot;
            const form = signatureInput ? signatureInput.closest('form') : null;
            if (form) {
                scheduleDraftSave(form);
            }
        });
    }

    if (signatureInput && signatureInput.value) {
        renderFromSignatureValue(signatureInput.value);
    }
}

function updateAcceptanceVisibility() {
    if (!signaturePad || !acceptanceSelect) {
        return;
    }

    const accepted = Boolean(acceptanceSelect.checked);
    signaturePad.classList.toggle('is-required', accepted);

    if (signatureHelp) {
        signatureHelp.textContent = accepted
            ? 'Assinatura obrigatória: conclua o desenho para liberar o envio.'
            : 'Quando o aceite for Sim, a assinatura se torna obrigatória.';
    }
}

function syncChoiceCards() {
    document.querySelectorAll('[data-choice-card]').forEach((card) => {
        const input = card.querySelector('input[type="checkbox"], input[type="radio"]');
        if (!input) {
            return;
        }

        card.classList.toggle('is-selected', Boolean(input.checked));
        card.setAttribute('aria-checked', input.checked ? 'true' : 'false');
    });
}

function setCityOption(cityName, uf, ibge) {
    if (!citySelect) {
        return;
    }

    const normalizedCity = (cityName || '').trim();

    if (normalizedCity === '') {
        return;
    }

    const existingOption = Array.from(citySelect.options).find((option) => {
        return option.value.toLowerCase() === normalizedCity.toLowerCase();
    });

    if (existingOption) {
        citySelect.value = existingOption.value;
        syncCityMetadata();
        return;
    }

    const createdOption = document.createElement('option');
    createdOption.value = normalizedCity;
    createdOption.textContent = normalizedCity;
    createdOption.dataset.uf = uf || '';
    createdOption.dataset.ibge = ibge || '';

    citySelect.appendChild(createdOption);
    citySelect.value = normalizedCity;
    syncCityMetadata();
}

async function lookupCep() {
    if (!cepInput) {
        return;
    }

    const cep = normalizeCep(cepInput.value);

    if (cep.length !== 8) {
        setCepHelp('Informe um CEP com 8 digitos para tentar preencher cidade, estado e IBGE.');
        return;
    }

    if (cepLookupInFlight) {
        return;
    }

    cepLookupInFlight = true;
    setCepHelp('Buscando endereco pelo CEP...', 'loading');

    try {
        const response = await fetch(`${buildAppUrl('/api/cep/lookup')}?cep=${encodeURIComponent(cep)}`, {
            headers: {
                'Accept': 'application/json',
            },
        });

        const payload = await response.json();

        if (!response.ok || payload.status !== 'success') {
            setCepHelp('CEP nao localizado automaticamente. Voce ainda pode preencher manualmente.', 'warning');
            return;
        }

        setCityOption(payload.cidade, payload.estado, payload.ibge);

        if (stateInput) {
            stateInput.value = payload.estado || '';
        }

        if (ibgeInput) {
            ibgeInput.value = payload.ibge || '';
        }

        if (payload.logradouro && !document.querySelector('[name="endereco"]').value) {
            document.querySelector('[name="endereco"]').value = payload.logradouro;
        }

        if (payload.bairro && !document.querySelector('[name="bairro"]').value) {
            document.querySelector('[name="bairro"]').value = payload.bairro;
        }

        setCepHelp(`Endereco localizado para ${payload.cidade}/${payload.estado}.`, 'success');
    } catch (error) {
        // Sem internet ou CEP indisponivel: o usuario ainda pode preencher manualmente.
        setCepHelp('Nao foi possivel consultar o CEP agora. Preencha os campos manualmente.', 'warning');
    } finally {
        cepLookupInFlight = false;
    }
}

if (cepInput) {
    cepInput.addEventListener('blur', lookupCep);
    cepInput.addEventListener('change', lookupCep);
    cepInput.addEventListener('input', () => {
        const normalized = normalizeCep(cepInput.value);

        if (normalized !== cepInput.value.replace(/\D+/g, '')) {
            cepInput.value = normalized;
        }

        if (normalized.length === 8) {
            lookupCep();
        }
    });
}

function setGeoHelp(message, tone = 'neutral') {
    if (!geoHelp) {
        return;
    }

    geoHelp.textContent = message;
    geoHelp.dataset.tone = tone;
}

function parseCoordinates(value) {
    const match = String(value || '').trim().replace(';', ',').match(/^(-?\d+(?:[\.,]\d+)?)\s*,\s*(-?\d+(?:[\.,]\d+)?)$/);

    if (!match) {
        return null;
    }

    const latitude = Number(match[1].replace(',', '.'));
    const longitude = Number(match[2].replace(',', '.'));

    if (!Number.isFinite(latitude) || !Number.isFinite(longitude)) {
        return null;
    }

    if (latitude < -90 || latitude > 90 || longitude < -180 || longitude > 180) {
        return null;
    }

    return { latitude, longitude };
}

function updateGeoMapButton() {
    if (!geoMapButton) {
        return;
    }

    geoMapButton.disabled = false;
}

function writeCoordinateValues(latitude, longitude, accuracy = null, message = '') {
    if (!geoCoordinateInput) {
        return;
    }

    if (!Number.isFinite(latitude) || !Number.isFinite(longitude)) {
        setGeoHelp('Nao foi possivel ler a coordenada informada.', 'error');
        return;
    }

    if (latitude < -90 || latitude > 90 || longitude < -180 || longitude > 180) {
        setGeoHelp('Coordenada fora do intervalo valido de latitude e longitude.', 'error');
        return;
    }

    geoCoordinateInput.value = `${latitude.toFixed(6)},${longitude.toFixed(6)}`;

    if (geoAccuracyInput) {
        geoAccuracyInput.value = Number.isFinite(Number(accuracy)) ? String(Math.round(Number(accuracy))) : '';
    }

    if (geoCapturedAtInput) {
        geoCapturedAtInput.value = new Date().toISOString();
    }

    updateGeoMapButton();

    const precisionText = Number.isFinite(Number(accuracy)) && Number(accuracy) > 0
        ? ` Precisao aproximada: ${Math.round(Number(accuracy))}m.`
        : '';
    setGeoHelp(message || `Coordenada capturada com sucesso.${precisionText}`, Number(accuracy) > 50 ? 'warning' : 'success');

    if (geoCoordinateInput.form) {
        scheduleDraftSave(geoCoordinateInput.form);
    }
}

function writeCoordinates(position) {
    if (!position || !position.coords || !geoCoordinateInput) {
        return;
    }

    writeCoordinateValues(
        Number(position.coords.latitude),
        Number(position.coords.longitude),
        Number(position.coords.accuracy || 0)
    );
}

function captureGeolocation(options = {}) {
    if (!geoButton || !geoCoordinateInput) {
        return;
    }

    if (!navigator.geolocation) {
        setGeoHelp('Este navegador nao oferece GPS. Use um celular com localizacao ativa.', 'error');
        return;
    }

    if (window.isSecureContext === false) {
        setGeoHelp('Tentando capturar GPS. Em pagina HTTP alguns navegadores bloqueiam localizacao; se isso acontecer, use o ajuste manual ou acesse por HTTPS.', 'warning');
    } else {
        setGeoHelp(options.automatic ? 'Tentando capturar automaticamente a coordenada do celular...' : 'Solicitando coordenada do celular...', 'loading');
    }

    geoButton.disabled = true;
    geoButton.textContent = 'Capturando GPS...';

    let bestPosition = null;
    let watchId = null;
    let finished = false;

    const finish = (position = null, message = '') => {
        if (finished) {
            return;
        }

        finished = true;

        if (watchId !== null) {
            navigator.geolocation.clearWatch(watchId);
        }

        geoButton.disabled = false;
        geoButton.textContent = 'Capturar coordenada do celular';

        if (position) {
            writeCoordinates(position);
            return;
        }

        setGeoHelp(message || 'Nao foi possivel capturar a coordenada. Verifique permissao de localizacao e tente novamente.', 'error');
    };

    const onSuccess = (position) => {
        if (!bestPosition || Number(position.coords.accuracy || 99999) < Number(bestPosition.coords.accuracy || 99999)) {
            bestPosition = position;
            writeCoordinates(position);
        }

        if (Number(position.coords.accuracy || 99999) <= 25) {
            finish(position);
        }
    };

    const onError = (error) => {
        const message = error && error.code === 1
            ? 'Permissao de localizacao negada. Libere o GPS do navegador e tente novamente.'
            : 'Nao foi possivel capturar a coordenada agora. Tente novamente em area aberta ou com GPS ativo.';

        finish(bestPosition, bestPosition ? '' : message);
    };

    watchId = navigator.geolocation.watchPosition(onSuccess, onError, {
        enableHighAccuracy: true,
        timeout: 20000,
        maximumAge: 0,
    });

    window.setTimeout(() => finish(bestPosition, 'Tempo de GPS esgotado. Tente novamente em area aberta.'), 18000);
}

function maybeAutoCaptureGeolocation() {
    if (!geoCoordinateInput || !geoButton || autoGeoRequested) {
        return;
    }

    if (geoCoordinateInput.value.trim() !== '') {
        updateGeoMapButton();
        return;
    }

    autoGeoRequested = true;
    window.setTimeout(() => captureGeolocation({ automatic: true }), 700);
}

function setMapStatus(message, tone = 'neutral') {
    if (!mapStatus) {
        return;
    }

    mapStatus.textContent = message;
    mapStatus.dataset.tone = tone;
}

function loadLeaflet() {
    if (window.L) {
        return Promise.resolve(window.L);
    }

    return new Promise((resolve, reject) => {
        if (!document.querySelector('link[data-leaflet-css]')) {
            const stylesheet = document.createElement('link');
            stylesheet.rel = 'stylesheet';
            stylesheet.href = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css';
            stylesheet.dataset.leafletCss = 'true';
            document.head.appendChild(stylesheet);
        }

        const existingScript = document.querySelector('script[data-leaflet-js]');
        if (existingScript) {
            existingScript.addEventListener('load', () => resolve(window.L), { once: true });
            existingScript.addEventListener('error', reject, { once: true });
            return;
        }

        const script = document.createElement('script');
        script.src = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js';
        script.defer = true;
        script.dataset.leafletJs = 'true';
        script.addEventListener('load', () => resolve(window.L), { once: true });
        script.addEventListener('error', reject, { once: true });
        document.head.appendChild(script);
    });
}

function setPendingMapCoordinate(latitude, longitude, updateView = true) {
    pendingMapCoordinate = { latitude, longitude };

    if (mapMarker && mapInstance) {
        mapMarker.setLatLng([latitude, longitude]);
    } else if (window.L && mapInstance) {
        mapMarker = window.L.marker([latitude, longitude]).addTo(mapInstance);
    }

    if (mapInstance && updateView) {
        mapInstance.setView([latitude, longitude], 18);
    }

    setMapStatus(`Ponto marcado: ${latitude.toFixed(6)},${longitude.toFixed(6)}.`, 'success');
}

async function openMapModal() {
    if (!mapModal || !mapCanvas) {
        return;
    }

    mapModal.hidden = false;
    document.body.classList.add('modal-open');
    setMapStatus('Carregando mapa...', 'loading');

    let leaflet = null;
    try {
        leaflet = await loadLeaflet();
    } catch (error) {
        setMapStatus('Nao foi possivel carregar o mapa. O campo de coordenadas continua editável para preenchimento manual.', 'error');
        return;
    }

    const parsed = parseCoordinates(geoCoordinateInput ? geoCoordinateInput.value : '');
    const center = parsed || { latitude: -20.850552, longitude: -42.803886 };

    if (!mapInstance) {
        mapInstance = leaflet.map(mapCanvas).setView([center.latitude, center.longitude], parsed ? 18 : 13);
        leaflet.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
            maxZoom: 20,
            attribution: 'Tiles &copy; Esri',
        }).addTo(mapInstance);

        mapInstance.on('click', (event) => {
            setPendingMapCoordinate(event.latlng.lat, event.latlng.lng, false);
        });
    } else {
        mapInstance.setView([center.latitude, center.longitude], parsed ? 18 : 13);
    }

    if (parsed) {
        setPendingMapCoordinate(parsed.latitude, parsed.longitude, true);
    } else {
        setMapStatus('Clique no mapa para marcar a casa do cliente.', 'neutral');
    }

    window.setTimeout(() => {
        if (mapInstance) {
            mapInstance.invalidateSize();
        }
    }, 120);
}

function closeMapModal() {
    if (!mapModal) {
        return;
    }

    mapModal.hidden = true;
    document.body.classList.remove('modal-open');
}

if (geoMapButton) {
    geoMapButton.addEventListener('click', openMapModal);
}

if (mapCloseButton) {
    mapCloseButton.addEventListener('click', closeMapModal);
}

if (mapModal) {
    mapModal.addEventListener('click', (event) => {
        if (event.target === mapModal) {
            closeMapModal();
        }
    });
}

if (mapConfirmButton) {
    mapConfirmButton.addEventListener('click', () => {
        if (!pendingMapCoordinate) {
            setMapStatus('Clique no mapa para escolher um ponto antes de confirmar.', 'warning');
            return;
        }

        writeCoordinateValues(
            pendingMapCoordinate.latitude,
            pendingMapCoordinate.longitude,
            null,
            'Coordenada definida pelo mapa. Confira o ponto antes de prosseguir.'
        );
        closeMapModal();
    });
}

if (mapUseGpsButton) {
    mapUseGpsButton.addEventListener('click', () => {
        if (!navigator.geolocation) {
            setMapStatus('Este navegador nao oferece GPS.', 'error');
            return;
        }

        setMapStatus('Solicitando GPS para centralizar o mapa...', 'loading');
        navigator.geolocation.getCurrentPosition((position) => {
            setPendingMapCoordinate(
                Number(position.coords.latitude),
                Number(position.coords.longitude),
                true
            );
        }, () => {
            setMapStatus('Nao foi possivel obter GPS agora. Clique no mapa ou edite a coordenada manualmente.', 'warning');
        }, {
            enableHighAccuracy: true,
            timeout: 15000,
            maximumAge: 0,
        });
    });
}

if (geoButton) {
    geoButton.addEventListener('click', captureGeolocation);

    if (geoCoordinateInput && geoCoordinateInput.value.trim() !== '') {
        setGeoHelp('Coordenada restaurada do rascunho. Capture novamente se nao estiver no local da instalacao.', 'warning');
    }
}

if (geoCoordinateInput) {
    geoCoordinateInput.addEventListener('input', () => {
        const parsed = parseCoordinates(geoCoordinateInput.value);

        if (parsed) {
            setGeoHelp('Coordenada informada manualmente. Confira no mapa se necessário.', 'success');
        } else if (geoCoordinateInput.value.trim() !== '') {
            setGeoHelp('Formato esperado: latitude,longitude. Exemplo: -20.850552,-42.803886', 'warning');
        }

        updateGeoMapButton();
        if (geoCoordinateInput.form) {
            scheduleDraftSave(geoCoordinateInput.form);
        }
    });
}

updateGeoMapButton();

const autosaveForms = document.querySelectorAll('[data-autosave-form]');
const cpfHelp = cpfInput ? cpfInput.closest('.field')?.querySelector('[data-live-feedback="cpf"]') : null;
const loginHelp = loginInput ? loginInput.closest('.field')?.querySelector('[data-live-feedback="login"]') : null;
const systemLoginHelp = systemLoginInput ? systemLoginInput.closest('.field')?.querySelector('[data-live-feedback="system-login"]') : null;

if (loginInput) {
    loginInput.addEventListener('input', () => {
        const normalized = normalizeLoginValue(loginInput.value);

        if (loginInput.value !== normalized) {
            loginInput.value = normalized;
        }
    });
}

applyLiveValidation(cpfInput, validateCpfValue, (value) => String(value || '').replace(/\D+/g, '').slice(0, 14));
applyLiveValidation(document.querySelector('[data-phone-input]'), validatePhoneValue, formatPhoneValue);
applyLiveValidation(document.querySelector('[data-email-input]'), validateEmailValue);
applyLiveValidation(loginInput, (value) => {
    const normalized = normalizeLoginValue(value);

    if (!normalized) {
        return { valid: true, message: '' };
    }

    return /^[a-z0-9_-]+$/.test(normalized)
        ? { valid: true, message: '' }
        : { valid: false, message: 'Login invalido.' };
}, normalizeLoginValue);
applyLiveValidation(systemLoginInput, (value) => {
    const normalized = String(value || '').trim().toLowerCase();

    if (!normalized) {
        return { valid: true, message: '' };
    }

    return /^[a-z0-9_.@-]+$/.test(normalized)
        ? { valid: true, message: '' }
        : { valid: false, message: 'Informe um usuario ou e-mail valido.' };
}, (value) => String(value || '').trim().toLowerCase());

if (cpfInput) {
    cpfInput.addEventListener('input', () => {
        scheduleRemoteClientValidation('cpf_cnpj', cpfInput, cpfHelp, (value) => String(value || '').replace(/\D+/g, '').slice(0, 14), 11);
    });

    cpfInput.addEventListener('blur', () => {
        scheduleRemoteClientValidation('cpf_cnpj', cpfInput, cpfHelp, (value) => String(value || '').replace(/\D+/g, '').slice(0, 14), 11, 0);
    });
}

if (loginInput) {
    loginInput.addEventListener('input', () => {
        scheduleRemoteClientValidation('login', loginInput, loginHelp, normalizeLoginValue, 3);
    });

    loginInput.addEventListener('blur', () => {
        scheduleRemoteClientValidation('login', loginInput, loginHelp, normalizeLoginValue, 3, 0);
    });
}

if (systemLoginInput) {
    systemLoginInput.addEventListener('input', () => {
        const normalized = String(systemLoginInput.value || '').trim().toLowerCase();

        if (systemLoginInput.value !== normalized) {
            systemLoginInput.value = normalized;
        }

        scheduleRemoteClientValidation('login', systemLoginInput, systemLoginHelp, (value) => String(value || '').trim().toLowerCase(), 3, 450, '/api/usuario/validar', true);
    });

    systemLoginInput.addEventListener('blur', () => {
        scheduleRemoteClientValidation('login', systemLoginInput, systemLoginHelp, (value) => String(value || '').trim().toLowerCase(), 3, 0, '/api/usuario/validar', true);
    });
}

if (acceptanceSelect) {
    acceptanceSelect.addEventListener('change', updateAcceptanceVisibility);
    updateAcceptanceVisibility();
}

setupSignaturePad();
updatePlanOptions();
refreshPhotoQueue();
wirePhotoPicker(photoPickButton, photoInput, true);
wirePhotoPicker(photoCameraButton, photoCameraInput, false);

autosaveForms.forEach((form) => {
    wireAutosave(form);
});

if (acceptanceForm) {
    const acceptanceDocumentRequired = acceptanceForm.dataset.documentValidationRequired === '1';
    const acceptanceDocumentDigits = Math.max(1, Number.parseInt(acceptanceForm.dataset.documentValidationDigits || '3', 10) || 3);

    if (acceptanceDocumentInput) {
        acceptanceDocumentInput.addEventListener('input', () => {
            const sanitized = String(acceptanceDocumentInput.value || '').replace(/\D+/g, '').slice(0, acceptanceDocumentDigits);
            if (acceptanceDocumentInput.value !== sanitized) {
                acceptanceDocumentInput.value = sanitized;
            }

            if (acceptanceDocumentHelp) {
                acceptanceDocumentHelp.textContent = sanitized.length > 0
                    ? `${sanitized.length}/${acceptanceDocumentDigits} dígitos informados.`
                    : 'Digite apenas os primeiros dígitos para confirmar a identidade documentada.';
                acceptanceDocumentHelp.dataset.tone = sanitized.length === acceptanceDocumentDigits ? 'success' : 'neutral';
            }
        });
    }

    acceptanceForm.addEventListener('submit', (event) => {
        if (!acceptanceSelect || !acceptanceSelect.checked) {
            event.preventDefault();
            if (acceptanceDocumentHelp) {
                acceptanceDocumentHelp.textContent = 'Confirme o aceite para concluir.';
                acceptanceDocumentHelp.dataset.tone = 'warning';
            }
            const acceptanceChoice = acceptanceSelect?.closest('[data-acceptance-choice]');
            if (acceptanceChoice instanceof HTMLElement) {
                acceptanceChoice.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
            if (typeof acceptanceSelect.focus === 'function') {
                acceptanceSelect.focus({ preventScroll: true });
            }
            return;
        }

        if (acceptanceDocumentRequired && acceptanceDocumentInput) {
            const sanitized = String(acceptanceDocumentInput.value || '').replace(/\D+/g, '').slice(0, acceptanceDocumentDigits);

            if (sanitized.length !== acceptanceDocumentDigits) {
                event.preventDefault();
                if (acceptanceDocumentHelp) {
                    acceptanceDocumentHelp.textContent = `Informe os primeiros ${acceptanceDocumentDigits} dígitos do CPF/CNPJ para continuar.`;
                    acceptanceDocumentHelp.dataset.tone = 'warning';
                }
                acceptanceDocumentInput.focus({ preventScroll: true });
                acceptanceDocumentInput.scrollIntoView({ behavior: 'smooth', block: 'center' });
                return;
            }

            acceptanceDocumentInput.value = sanitized;
        }
    });
}

document.querySelectorAll('[data-close-page]').forEach((button) => {
    button.addEventListener('click', () => {
        if (window.history.length > 1) {
            window.history.back();
            return;
        }

        window.location.href = buildAppUrl('/');
    });
});

document.querySelectorAll('[data-pppoe-secret-toggle]').forEach((button) => {
    const container = button.closest('.pppoe-secret');
    const input = container?.querySelector('[data-pppoe-secret-input]');
    const defaultLabel = button.getAttribute('data-pppoe-secret-label') || 'Mostrar senha';

    if (!(input instanceof HTMLInputElement)) {
        return;
    }

    const showSecret = () => {
        input.type = 'text';
        button.textContent = 'Ocultar senha';
    };

    const hideSecret = () => {
        input.type = 'password';
        button.textContent = defaultLabel;
    };

    button.addEventListener('click', () => {
        if (input.type === 'password') {
            showSecret();
            return;
        }

        hideSecret();
    });

    button.addEventListener('pointerdown', showSecret);
    button.addEventListener('pointerup', hideSecret);
    button.addEventListener('pointerleave', hideSecret);
    button.addEventListener('pointercancel', hideSecret);
});

const permissionEditor = document.querySelector('[data-permission-editor]');

if (permissionEditor instanceof HTMLElement) {
    const rowsContainer = permissionEditor.querySelector('[data-permission-rows]');
    const template = permissionEditor.querySelector('[data-permission-row-template]');
    const addButton = permissionEditor.querySelector('[data-permission-add]');

    const bindPermissionRow = (row) => {
        const removeButton = row.querySelector('[data-permission-remove]');
        if (removeButton instanceof HTMLButtonElement) {
            removeButton.addEventListener('click', () => {
                const rows = rowsContainer?.querySelectorAll('.permissions-row') || [];
                if (rows.length <= 1) {
                    row.querySelectorAll('input').forEach((input) => {
                        if (input.type === 'checkbox') {
                            input.checked = input.name === 'permission_tecnico[]';
                            return;
                        }

                        input.value = '';
                    });
                    return;
                }

                row.remove();
            });
        }
    };

    permissionEditor.querySelectorAll('.permissions-row').forEach((row) => bindPermissionRow(row));

    if (rowsContainer && template instanceof HTMLTemplateElement && addButton instanceof HTMLButtonElement) {
        addButton.addEventListener('click', () => {
            const nextIndex = rowsContainer.querySelectorAll('.permissions-row').length;
            const html = template.innerHTML.replaceAll('__INDEX__', String(nextIndex));
            const wrapper = document.createElement('div');
            wrapper.innerHTML = html.trim();
            const row = wrapper.firstElementChild;
            if (row) {
                bindPermissionRow(row);
                rowsContainer.appendChild(row);
            }
        });
    }
}

async function copyTextToClipboard(text) {
    const normalizedText = String(text || '').trim();

    if (!normalizedText) {
        throw new Error('Texto vazio.');
    }

    if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
        await navigator.clipboard.writeText(normalizedText);
        return;
    }

    const textarea = document.createElement('textarea');
    textarea.value = normalizedText;
    textarea.setAttribute('readonly', 'readonly');
    textarea.style.position = 'fixed';
    textarea.style.top = '-9999px';
    textarea.style.left = '-9999px';
    document.body.appendChild(textarea);
    textarea.select();
    textarea.setSelectionRange(0, textarea.value.length);
    const successful = document.execCommand('copy');
    document.body.removeChild(textarea);

    if (!successful) {
        throw new Error('Falha ao copiar.');
    }
}

document.querySelectorAll('[data-copy-text]').forEach((button) => {
    button.addEventListener('click', async () => {
        const originalLabel = button.dataset.copyLabel || button.textContent || 'Copiar';
        const copyText = button.getAttribute('data-copy-text') || '';

        try {
            await copyTextToClipboard(copyText);
            button.textContent = 'Copiado';
            button.disabled = true;
            window.setTimeout(() => {
                button.textContent = originalLabel;
                button.disabled = false;
            }, 1600);
        } catch (error) {
            const fallback = window.prompt('Copie o link abaixo:', copyText);
            if (fallback !== null) {
                button.textContent = 'Copiado';
                button.disabled = true;
                window.setTimeout(() => {
                    button.textContent = originalLabel;
                    button.disabled = false;
                }, 1600);
            }
        }
    });
});
