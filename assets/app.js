import './styles/app.css';

const showToast = (message) => {
    const region = document.querySelector('.toast-region');
    if (!region) {
        return;
    }

    const toast = document.createElement('div');
    toast.className = 'toast';
    toast.textContent = message;
    region.append(toast);
    window.setTimeout(() => toast.remove(), 3000);
};

document.querySelectorAll('[data-mock-action]').forEach((button) => {
    button.addEventListener('click', () => showToast(button.dataset.mockAction));
});

document.querySelectorAll('[data-dialog-open]').forEach((button) => {
    button.addEventListener('click', () => {
        const dialog = document.getElementById(button.dataset.dialogOpen);
        if (dialog instanceof HTMLDialogElement) {
            dialog.showModal();
        }
    });
});

document.querySelectorAll('[data-dialog-close]').forEach((button) => {
    button.addEventListener('click', () => {
        const dialog = button.closest('dialog');
        if (dialog instanceof HTMLDialogElement) {
            dialog.close();
        }
    });
});

document.querySelectorAll('[data-mock-form]').forEach((form) => {
    form.addEventListener('submit', (event) => {
        event.preventDefault();
        showToast(form.dataset.mockForm);
        const dialog = form.closest('dialog');
        if (dialog instanceof HTMLDialogElement) {
            dialog.close();
        }
    });
});

document.querySelectorAll('[data-tab-target]').forEach((tab) => {
    tab.addEventListener('click', () => {
        document.querySelectorAll('[data-tab-target]').forEach((item) => {
            const selected = item === tab;
            item.classList.toggle('is-active', selected);
            item.setAttribute('aria-selected', selected.toString());
        });
        document.querySelectorAll('[data-tab-panel]').forEach((panel) => {
            const selected = panel.dataset.tabPanel === tab.dataset.tabTarget;
            panel.classList.toggle('is-active', selected);
            panel.hidden = !selected;
        });
    });
});

const streamerList = document.querySelector('[data-streamer-list]');

if (streamerList) {
    const storageKey = 'stream-notify-bot.admin-ui.streamers.v1';
    const allowedPlatforms = ['youtube', 'twitch', 'twitcasting'];
    const allowedAgencies = ['independent', 'unconnected'];
    const platformLabels = { youtube: 'YouTube', twitch: 'Twitch', twitcasting: 'TwitCasting' };
    const platformMarks = { youtube: '▶', twitch: '◧', twitcasting: '●' };
    const agencyLabels = { independent: '個人勢', unconnected: '所属区分（未接続）' };
    const searchInput = document.querySelector('[data-table-search="streamer-table"]');
    const agencyFilter = document.querySelector('[data-streamer-agency-filter]');
    const stateFilter = document.querySelector('[data-streamer-state-filter]');
    const countLabel = document.querySelector('[data-streamer-count]');
    const form = document.querySelector('[data-streamer-form]');

    const isStreamer = (value) => value
        && typeof value.id === 'string'
        && typeof value.nameJa === 'string'
        && value.nameJa.trim().length > 0
        && value.nameJa.length <= 100
        && typeof value.nameEn === 'string'
        && value.nameEn.length <= 100
        && allowedAgencies.includes(value.agency)
        && /^#[0-9A-Fa-f]{6}$/.test(value.color)
        && allowedPlatforms.includes(value.platform)
        && typeof value.identifier === 'string'
        && value.identifier.trim().length > 0
        && value.identifier.length <= 255
        && typeof value.enabled === 'boolean';

    const loadStreamers = () => {
        try {
            const stored = JSON.parse(window.localStorage.getItem(storageKey) ?? '[]');
            return Array.isArray(stored) ? stored.filter(isStreamer) : [];
        } catch {
            return [];
        }
    };

    let streamers = loadStreamers();

    const saveStreamers = () => {
        try {
            window.localStorage.setItem(storageKey, JSON.stringify(streamers));
            return true;
        } catch {
            showToast('ブラウザー内へ保存できませんでした');
            return false;
        }
    };

    const element = (tagName, className, text) => {
        const node = document.createElement(tagName);
        if (className) {
            node.className = className;
        }
        if (text !== undefined) {
            node.textContent = text;
        }
        return node;
    };

    const applyStreamerFilters = () => {
        const query = searchInput instanceof HTMLInputElement
            ? searchInput.value.trim().toLocaleLowerCase('ja')
            : '';
        const agency = agencyFilter instanceof HTMLSelectElement ? agencyFilter.value : 'all';
        const state = stateFilter instanceof HTMLSelectElement ? stateFilter.value : 'all';
        let visibleCount = 0;

        streamerList.querySelectorAll('[data-streamer-row]').forEach((row) => {
            const matchesQuery = row.textContent.toLocaleLowerCase('ja').includes(query);
            const matchesAgency = agency === 'all' || row.dataset.agency === agency;
            const matchesState = state === 'all' || row.dataset.state === state;
            row.hidden = !(matchesQuery && matchesAgency && matchesState);
            if (!row.hidden) {
                visibleCount += 1;
            }
        });

        if (countLabel) {
            countLabel.textContent = streamers.length === 0
                ? '0件'
                : `${visibleCount}件 / 全${streamers.length}件`;
        }
    };

    const createStreamerRow = (streamer) => {
        const row = element('tr');
        row.dataset.streamerRow = '';
        row.dataset.streamerId = streamer.id;
        row.dataset.agency = streamer.agency;
        row.dataset.state = streamer.enabled ? 'active' : 'paused';

        const personCell = element('td');
        const person = element('div', 'person-cell');
        const avatar = element('span', 'avatar', streamer.nameJa.slice(0, 2));
        avatar.style.background = streamer.color;
        const names = element('div');
        names.append(element('strong', '', streamer.nameJa), element('small', '', streamer.identifier));
        person.append(avatar, names);
        personCell.append(person);

        const agencyCell = element('td', '', agencyLabels[streamer.agency]);
        const platformCell = element('td');
        const platformStack = element('div', 'platform-stack');
        const platform = element('span', `platform-logo ${streamer.platform}`, platformMarks[streamer.platform]);
        platform.title = platformLabels[streamer.platform];
        platformStack.append(platform);
        platformCell.append(platformStack);

        const stateCell = element('td');
        const stateBadge = element(
            'span',
            `state-badge ${streamer.enabled ? 'state-active' : 'state-paused'}`,
            streamer.enabled ? '有効' : '停止中',
        );
        stateBadge.prepend(element('i'));
        stateCell.append(stateBadge);

        const syncedCell = element('td');
        syncedCell.append(element('span', 'muted', 'ブラウザー内モック'));

        const actionCell = element('td');
        const actions = element('div', 'row-actions');
        const toggle = element('button', 'row-action', streamer.enabled ? '停止' : '再開');
        toggle.type = 'button';
        toggle.dataset.streamerAction = 'toggle';
        const remove = element('button', 'row-action row-action-danger', '削除');
        remove.type = 'button';
        remove.dataset.streamerAction = 'remove';
        actions.append(toggle, remove);
        actionCell.append(actions);

        row.append(personCell, agencyCell, platformCell, stateCell, syncedCell, actionCell);
        return row;
    };

    const renderStreamers = () => {
        streamerList.replaceChildren();
        if (streamers.length === 0) {
            const row = element('tr', 'empty-table-row');
            const cell = element('td');
            cell.colSpan = 6;
            cell.append(
                element('strong', '', '配信者はまだ登録されていません'),
                element('span', '', '「配信者を追加」からブラウザー内のモックデータを登録できます。'),
            );
            row.append(cell);
            streamerList.append(row);
        } else {
            streamers.forEach((streamer) => streamerList.append(createStreamerRow(streamer)));
        }
        applyStreamerFilters();
    };

    [searchInput, agencyFilter, stateFilter].forEach((control) => {
        control?.addEventListener('input', applyStreamerFilters);
        control?.addEventListener('change', applyStreamerFilters);
    });

    form?.addEventListener('submit', (event) => {
        event.preventDefault();
        if (!(form instanceof HTMLFormElement) || !form.reportValidity()) {
            return;
        }

        const data = new FormData(form);
        const color = String(data.get('color') ?? '').trim().toUpperCase();
        const platform = String(data.get('platform') ?? '');
        const agency = String(data.get('agency') ?? '');
        if (!/^#[0-9A-F]{6}$/.test(color)
            || !allowedPlatforms.includes(platform)
            || !allowedAgencies.includes(agency)) {
            showToast('入力内容を確認してください');
            return;
        }

        const streamer = {
            id: window.crypto.randomUUID(),
            nameJa: String(data.get('nameJa') ?? '').trim(),
            nameEn: String(data.get('nameEn') ?? '').trim(),
            agency,
            color,
            platform,
            identifier: String(data.get('identifier') ?? '').trim(),
            enabled: true,
        };
        if (streamer.nameJa === '' || streamer.identifier === '') {
            showToast('必須項目を入力してください');
            return;
        }
        if (streamers.some((existing) => existing.platform === streamer.platform
            && existing.identifier.toLocaleLowerCase('en') === streamer.identifier.toLocaleLowerCase('en'))) {
            showToast('同じプラットフォームと登録用識別子が既にあります');
            return;
        }

        streamers.push(streamer);
        if (!saveStreamers()) {
            streamers.pop();
            return;
        }
        renderStreamers();
        form.reset();
        const dialog = form.closest('dialog');
        if (dialog instanceof HTMLDialogElement) {
            dialog.close();
        }
        showToast('配信者をブラウザー内へ追加しました');
    });

    streamerList.addEventListener('click', (event) => {
        const button = event.target instanceof Element
            ? event.target.closest('[data-streamer-action]')
            : null;
        const row = button?.closest('[data-streamer-row]');
        if (!(button instanceof HTMLButtonElement) || !(row instanceof HTMLTableRowElement)) {
            return;
        }
        const index = streamers.findIndex((streamer) => streamer.id === row.dataset.streamerId);
        if (index < 0) {
            return;
        }

        if (button.dataset.streamerAction === 'toggle') {
            const previousState = streamers[index].enabled;
            streamers[index].enabled = !streamers[index].enabled;
            if (!saveStreamers()) {
                streamers[index].enabled = previousState;
                return;
            }
            renderStreamers();
            showToast(streamers[index].enabled ? '配信者を再開しました' : '配信者を停止しました');
        } else if (button.dataset.streamerAction === 'remove'
            && window.confirm('このブラウザー内のモックデータを削除しますか？')) {
            const removed = streamers.splice(index, 1)[0];
            if (!saveStreamers()) {
                streamers.splice(index, 0, removed);
                return;
            }
            renderStreamers();
            showToast('配信者を削除しました');
        }
    });

    document.querySelector('[data-streamer-clear]')?.addEventListener('click', () => {
        if (streamers.length > 0 && window.confirm('このブラウザー内の配信者モックデータをすべて削除しますか？')) {
            const previousStreamers = streamers;
            streamers = [];
            if (!saveStreamers()) {
                streamers = previousStreamers;
                return;
            }
            renderStreamers();
            showToast('モックデータをすべて削除しました');
        }
    });

    const colorPicker = form?.querySelector('[data-streamer-color-picker]');
    const colorInput = form?.querySelector('[data-streamer-color]');
    colorPicker?.addEventListener('input', () => {
        if (colorPicker instanceof HTMLInputElement && colorInput instanceof HTMLInputElement) {
            colorInput.value = colorPicker.value.toUpperCase();
        }
    });
    colorInput?.addEventListener('input', () => {
        if (colorPicker instanceof HTMLInputElement
            && colorInput instanceof HTMLInputElement
            && /^#[0-9A-Fa-f]{6}$/.test(colorInput.value)) {
            colorPicker.value = colorInput.value;
        }
    });

    renderStreamers();
}

document.querySelectorAll('[data-secret-toggle]').forEach((button) => {
    button.addEventListener('click', () => {
        const input = button.parentElement?.querySelector('input');
        if (input instanceof HTMLInputElement) {
            input.type = input.type === 'password' ? 'text' : 'password';
        }
    });
});

document.querySelectorAll('[data-sidebar-toggle]').forEach((button) => {
    button.addEventListener('click', () => {
        document.getElementById('admin-sidebar')?.classList.toggle('is-open');
        document.querySelector('.sidebar-backdrop')?.classList.toggle('is-open');
    });
});

document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
        document.getElementById('admin-sidebar')?.classList.remove('is-open');
        document.querySelector('.sidebar-backdrop')?.classList.remove('is-open');
    }
});
