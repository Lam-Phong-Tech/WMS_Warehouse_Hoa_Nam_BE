import { frontendEnv, isProductionLike } from './shared/env';
import { checkFrontendHealth } from './shared/health';

const root = document.querySelector('[data-wms-health-root]');

function setText(selector, value) {
    const node = root?.querySelector(selector);
    if (node) {
        node.textContent = value;
    }
}

function showState(state) {
    root?.querySelectorAll('[data-state]').forEach((node) => {
        node.hidden = node.dataset.state !== state;
    });
}

async function renderHealth() {
    if (!root) {
        return;
    }

    setText('[data-env-app]', frontendEnv.appName);
    setText('[data-env-name]', frontendEnv.appEnv);
    setText('[data-env-api]', frontendEnv.apiBaseUrl);
    setText('[data-env-health]', frontendEnv.healthEndpoint);
    setText('[data-env-version]', frontendEnv.buildVersion);
    setText('[data-env-sha]', frontendEnv.buildSha);

    if (isProductionLike && frontendEnv.buildSha === 'local') {
        showState('error');
        setText('[data-health-message]', 'Production-like environment requires VITE_BUILD_SHA.');
        return;
    }

    showState('loading');
    const result = await checkFrontendHealth();

    setText('[data-health-status]', result.status ? String(result.status) : 'N/A');
    setText('[data-health-latency]', `${result.latencyMs} ms`);
    setText('[data-health-checked-at]', result.checkedAt);
    setText('[data-health-body]', result.body || '(empty body)');

    showState(result.ok ? 'success' : 'error');
    setText(
        '[data-health-message]',
        result.ok
            ? 'Frontend build can reach the configured backend health endpoint.'
            : 'Frontend build cannot reach the configured backend health endpoint.',
    );
}

root?.querySelector('[data-health-refresh]')?.addEventListener('click', (event) => {
    event.currentTarget.setAttribute('disabled', 'disabled');
    renderHealth().finally(() => event.currentTarget.removeAttribute('disabled'));
});

renderHealth();
