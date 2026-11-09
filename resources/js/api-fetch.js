function newRequestKey() {
    if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
        return crypto.randomUUID();
    }

    const bytes = new Uint8Array(16);
    crypto.getRandomValues(bytes);

    return Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('');
}

function responseMessage(payload, response) {
    if (payload && typeof payload === 'object') {
        if (typeof payload.message === 'string' && payload.message !== '') {
            return payload.message;
        }

        if (payload.errors && typeof payload.errors === 'object') {
            const firstError = Object.values(payload.errors).flat().find((error) => typeof error === 'string');
            if (firstError) {
                return firstError;
            }
        }
    }

    return `Request failed (${response.status} ${response.statusText}).`;
}

window.apiFetch = async function apiFetch(url, options = {}) {
    const { responseType = 'json', headers: suppliedHeaders, ...fetchOptions } = options;
    const headers = new Headers(suppliedHeaders || {});
    const method = (fetchOptions.method || 'GET').toUpperCase();
    const mutating = !['GET', 'HEAD', 'OPTIONS'].includes(method);

    if (!headers.has('Accept')) {
        headers.set('Accept', responseType === 'text' ? 'text/html, application/json' : 'application/json');
    }
    headers.set('X-Requested-With', 'XMLHttpRequest');

    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;
    if (mutating && csrfToken && !headers.has('X-CSRF-TOKEN')) {
        headers.set('X-CSRF-TOKEN', csrfToken);
    }
    if (mutating && !headers.has('X-Idempotency-Key')) {
        headers.set('X-Idempotency-Key', newRequestKey());
    }

    let response;
    try {
        response = await fetch(url, { ...fetchOptions, method, headers });
    } catch (error) {
        window.toast?.error('Network error — check your connection and try again.');
        throw error;
    }

    const isJson = response.headers.get('content-type')?.includes('application/json');
    let payload;
    if (response.status !== 204) {
        payload = responseType === 'text' ? await response.text() : (isJson ? await response.json() : null);
    }

    if (!response.ok) {
        const message = responseMessage(payload, response);
        window.toast?.error(message);
        throw new Error(message);
    }

    if (responseType === 'text') {
        return payload ?? '';
    }

    if (!isJson && response.status !== 204) {
        const message = 'The server returned an unexpected response.';
        window.toast?.error(message);
        throw new Error(message);
    }

    return payload ?? null;
};
