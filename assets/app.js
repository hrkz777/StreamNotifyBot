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

const streamerStorageKey = 'stream-notify-bot.admin-ui.streamers.v1';
const streamerAllowedPlatforms = ['youtube', 'twitch', 'twitcasting'];
const streamerAllowedAgencies = ['independent', 'unconnected'];
const isStoredStreamer = (value) => value
    && typeof value.id === 'string'
    && typeof value.nameJa === 'string'
    && value.nameJa.trim().length > 0
    && value.nameJa.length <= 100
    && typeof value.nameEn === 'string'
    && value.nameEn.length <= 100
    && streamerAllowedAgencies.includes(value.agency)
    && /^#[0-9A-Fa-f]{6}$/.test(value.color)
    && streamerAllowedPlatforms.includes(value.platform)
    && typeof value.identifier === 'string'
    && value.identifier.trim().length > 0
    && value.identifier.length <= 255
    && typeof value.enabled === 'boolean';
const loadStoredStreamers = () => {
    try {
        const stored = JSON.parse(window.localStorage.getItem(streamerStorageKey) ?? '[]');
        return Array.isArray(stored) ? stored.filter(isStoredStreamer) : [];
    } catch {
        return [];
    }
};
const streamerList = document.querySelector('[data-streamer-list]');

if (streamerList) {
    const platformLabels = { youtube: 'YouTube', twitch: 'Twitch', twitcasting: 'TwitCasting' };
    const platformMarks = { youtube: '▶', twitch: '◧', twitcasting: '●' };
    const agencyLabels = { independent: '個人勢', unconnected: '所属区分（未接続）' };
    const searchInput = document.querySelector('[data-table-search="streamer-table"]');
    const agencyFilter = document.querySelector('[data-streamer-agency-filter]');
    const stateFilter = document.querySelector('[data-streamer-state-filter]');
    const countLabel = document.querySelector('[data-streamer-count]');
    const form = document.querySelector('[data-streamer-form]');

    let streamers = loadStoredStreamers();

    const saveStreamers = () => {
        try {
            window.localStorage.setItem(streamerStorageKey, JSON.stringify(streamers));
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
            || !streamerAllowedPlatforms.includes(platform)
            || !streamerAllowedAgencies.includes(agency)) {
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

const dashboardStreamerCount = document.querySelector('[data-dashboard-streamer-count]');
const dashboardPlatformSummary = document.querySelector('[data-dashboard-platform-summary]');

if (dashboardStreamerCount && dashboardPlatformSummary) {
    try {
        const streamers = loadStoredStreamers();
        const platforms = new Set(
            streamers
                .map((streamer) => streamer?.platform)
                .filter((platform) => streamerAllowedPlatforms.includes(platform)),
        );
        dashboardStreamerCount.textContent = streamers.length.toString();
        dashboardPlatformSummary.textContent = platforms.size === 0
            ? '未登録'
            : `${platforms.size}プラットフォーム`;
    } catch {
        dashboardStreamerCount.textContent = '0';
        dashboardPlatformSummary.textContent = '読込エラー';
    }
}

const notificationStorageKey = 'stream-notify-bot.admin-ui.notifications.v1';
const notificationEventTypes = ['video', 'scheduled', 'live', 'ended'];
const notificationAllowedColors = ['purple', 'blue', 'pink', 'orange'];
const notificationRoot = document.querySelector('[data-notification-root]');

if (notificationRoot) {
    const list = notificationRoot.querySelector('[data-notification-list]');
    const empty = notificationRoot.querySelector('[data-notification-empty]');
    const editor = notificationRoot.querySelector('[data-notification-editor]');
    const form = notificationRoot.querySelector('[data-notification-form]');
    const createForm = document.querySelector('[data-notification-create-form]');
    const streamerForm = document.querySelector('[data-notification-streamers-form]');
    const notificationStreamerList = document.querySelector('[data-notification-streamer-list]');
    const count = document.querySelector('[data-notification-count]');
    const destinationCount = document.querySelector('[data-notification-destination-count]');

    const isHttpsUrl = (value) => {
        if (value === '') {
            return true;
        }
        try {
            return new URL(value).protocol === 'https:';
        } catch {
            return false;
        }
    };
    const isStoredNotification = (value) => value
        && typeof value.id === 'string'
        && typeof value.name === 'string'
        && value.name.trim().length > 0
        && value.name.length <= 100
        && typeof value.description === 'string'
        && value.description.length <= 200
        && notificationAllowedColors.includes(value.color)
        && typeof value.enabled === 'boolean'
        && typeof value.updatedAt === 'string'
        && (value.streamerIds === undefined || (Array.isArray(value.streamerIds)
            && value.streamerIds.every((streamerId) => typeof streamerId === 'string')))
        && value.webhooks
        && notificationEventTypes.every((eventType) => typeof value.webhooks[eventType] === 'string'
            && value.webhooks[eventType].length <= 500
            && isHttpsUrl(value.webhooks[eventType]));
    const loadNotifications = () => {
        try {
            const stored = JSON.parse(window.localStorage.getItem(notificationStorageKey) ?? '[]');
            return Array.isArray(stored)
                ? stored.filter(isStoredNotification).map((notification) => ({
                    ...notification,
                    streamerIds: notification.streamerIds ?? [],
                }))
                : [];
        } catch {
            return [];
        }
    };
    const makeElement = (tagName, className, text) => {
        const node = document.createElement(tagName);
        if (className) {
            node.className = className;
        }
        if (text !== undefined) {
            node.textContent = text;
        }
        return node;
    };
    const formControl = (name) => form instanceof HTMLFormElement
        ? form.elements.namedItem(name)
        : null;

    let notifications = loadNotifications();
    let selectedId = notifications[0]?.id ?? null;

    const saveNotifications = () => {
        try {
            window.localStorage.setItem(notificationStorageKey, JSON.stringify(notifications));
            return true;
        } catch {
            showToast('ブラウザー内へ保存できませんでした');
            return false;
        }
    };
    const selectedNotification = () => notifications.find((notification) => notification.id === selectedId);
    const updateWebhookCard = (eventType, value) => {
        const card = notificationRoot.querySelector(`[data-webhook-card="${eventType}"]`);
        const state = card?.querySelector('[data-webhook-state]');
        const enabled = value.trim() !== '';
        card?.classList.toggle('is-empty', !enabled);
        if (state) {
            state.className = `state-badge ${enabled ? 'state-active' : 'state-paused'}`;
            state.replaceChildren(makeElement('i'), document.createTextNode(enabled ? '送信する' : '送信しない'));
        }
    };
    const renderEditor = () => {
        const notification = selectedNotification();
        if (!notification || !(form instanceof HTMLFormElement)) {
            if (empty instanceof HTMLElement) {
                empty.hidden = false;
            }
            if (editor instanceof HTMLElement) {
                editor.hidden = true;
            }
            return;
        }

        if (empty instanceof HTMLElement) {
            empty.hidden = true;
        }
        if (editor instanceof HTMLElement) {
            editor.hidden = false;
        }
        const name = formControl('name');
        const description = formControl('description');
        const enabled = formControl('enabled');
        if (name instanceof HTMLInputElement) {
            name.value = notification.name;
        }
        if (description instanceof HTMLInputElement) {
            description.value = notification.description;
        }
        if (enabled instanceof HTMLInputElement) {
            enabled.checked = notification.enabled;
        }
        notificationRoot.querySelector('[data-notification-heading]').textContent = notification.name;
        notificationRoot.querySelector('[data-notification-description]').textContent = notification.description || '説明はありません';
        const swatch = notificationRoot.querySelector('[data-notification-swatch]');
        if (swatch) {
            swatch.className = `route-swatch ${notification.color}`;
        }
        const enabledLabel = notificationRoot.querySelector('[data-notification-enabled-label]');
        if (enabledLabel) {
            enabledLabel.textContent = notification.enabled ? '有効' : '停止中';
        }
        notificationEventTypes.forEach((eventType) => {
            const input = formControl(`webhook_${eventType}`);
            if (input instanceof HTMLInputElement) {
                input.value = notification.webhooks[eventType];
            }
            updateWebhookCard(eventType, notification.webhooks[eventType]);
        });
        const updated = notificationRoot.querySelector('[data-notification-updated]');
        if (updated) {
            updated.textContent = `最終更新: ${new Date(notification.updatedAt).toLocaleString('ja-JP')}`;
        }
        const streamerSummary = notificationRoot.querySelector('[data-notification-streamer-summary]');
        if (streamerSummary) {
            const registeredIds = new Set(loadStoredStreamers().map((streamer) => streamer.id));
            const linkedCount = notification.streamerIds.filter((streamerId) => registeredIds.has(streamerId)).length;
            streamerSummary.textContent = `${linkedCount}名`;
        }
    };
    const renderNotifications = () => {
        if (!list) {
            return;
        }
        list.replaceChildren();
        if (notifications.length === 0) {
            const message = makeElement('div', 'route-list-empty');
            message.append(
                makeElement('strong', '', '通知設定はありません'),
                makeElement('small', '', '追加した設定だけが表示されます。'),
            );
            list.append(message);
        } else {
            notifications.forEach((notification) => {
                const button = makeElement('button', `route-item${notification.id === selectedId ? ' is-active' : ''}`);
                button.type = 'button';
                button.dataset.notificationId = notification.id;
                const webhookCount = notificationEventTypes.filter(
                    (eventType) => notification.webhooks[eventType] !== '',
                ).length;
                const label = makeElement('span');
                label.append(
                    makeElement('strong', '', notification.name),
                    makeElement('small', '', `${webhookCount}種別・${notification.enabled ? '有効' : '停止中'}`),
                );
                button.append(
                    makeElement('span', `route-swatch ${notification.color}`),
                    label,
                    makeElement('span', '', '›'),
                );
                list.append(button);
            });
        }
        if (count) {
            count.textContent = notifications.length.toString();
        }
        if (destinationCount) {
            destinationCount.textContent = notifications
                .filter((notification) => notification.enabled)
                .reduce((total, notification) => total + notificationEventTypes.filter(
                    (eventType) => notification.webhooks[eventType] !== '',
                ).length, 0)
                .toString();
        }
        renderEditor();
    };

    list?.addEventListener('click', (event) => {
        const button = event.target instanceof Element
            ? event.target.closest('[data-notification-id]')
            : null;
        if (!(button instanceof HTMLButtonElement)) {
            return;
        }
        selectedId = button.dataset.notificationId ?? null;
        renderNotifications();
    });

    createForm?.addEventListener('submit', (event) => {
        event.preventDefault();
        if (!(createForm instanceof HTMLFormElement) || !createForm.reportValidity()) {
            return;
        }
        const data = new FormData(createForm);
        const name = String(data.get('name') ?? '').trim();
        const description = String(data.get('description') ?? '').trim();
        const color = String(data.get('color') ?? '');
        if (name === '' || !notificationAllowedColors.includes(color)) {
            showToast('入力内容を確認してください');
            return;
        }
        if (notifications.some((notification) => notification.name.toLocaleLowerCase('ja') === name.toLocaleLowerCase('ja'))) {
            showToast('同じ名前の通知設定が既にあります');
            return;
        }

        const notification = {
            id: window.crypto.randomUUID(),
            name,
            description,
            color,
            enabled: true,
            updatedAt: new Date().toISOString(),
            streamerIds: [],
            webhooks: Object.fromEntries(notificationEventTypes.map((eventType) => [eventType, ''])),
        };
        notifications.push(notification);
        if (!saveNotifications()) {
            notifications.pop();
            return;
        }
        selectedId = notification.id;
        createForm.reset();
        const dialog = createForm.closest('dialog');
        if (dialog instanceof HTMLDialogElement) {
            dialog.close();
        }
        renderNotifications();
        showToast('通知設定をブラウザー内へ追加しました');
    });

    form?.addEventListener('input', (event) => {
        const target = event.target;
        if (target instanceof HTMLInputElement && target.name.startsWith('webhook_')) {
            updateWebhookCard(target.name.slice('webhook_'.length), target.value);
        }
        if (target instanceof HTMLInputElement && target.name === 'enabled') {
            const enabledLabel = notificationRoot.querySelector('[data-notification-enabled-label]');
            if (enabledLabel) {
                enabledLabel.textContent = target.checked ? '有効' : '停止中';
            }
        }
    });

    form?.addEventListener('submit', (event) => {
        event.preventDefault();
        if (!(form instanceof HTMLFormElement) || !form.reportValidity()) {
            return;
        }
        const notification = selectedNotification();
        if (!notification) {
            return;
        }
        const nameControl = formControl('name');
        const descriptionControl = formControl('description');
        const enabledControl = formControl('enabled');
        const name = nameControl instanceof HTMLInputElement ? nameControl.value.trim() : '';
        if (name === '') {
            showToast('設定名を入力してください');
            return;
        }
        if (notifications.some((item) => item.id !== notification.id
            && item.name.toLocaleLowerCase('ja') === name.toLocaleLowerCase('ja'))) {
            showToast('同じ名前の通知設定が既にあります');
            return;
        }
        const webhooks = {};
        for (const eventType of notificationEventTypes) {
            const control = formControl(`webhook_${eventType}`);
            const value = control instanceof HTMLInputElement ? control.value.trim() : '';
            if (!isHttpsUrl(value)) {
                showToast('Webhook URLは空欄またはHTTPS URLにしてください');
                control?.focus();
                return;
            }
            webhooks[eventType] = value;
        }

        const previous = { ...notification };
        notification.name = name;
        notification.description = descriptionControl instanceof HTMLInputElement
            ? descriptionControl.value.trim()
            : '';
        notification.enabled = enabledControl instanceof HTMLInputElement && enabledControl.checked;
        notification.updatedAt = new Date().toISOString();
        notification.webhooks = webhooks;
        if (!saveNotifications()) {
            Object.assign(notification, previous);
            return;
        }
        renderNotifications();
        showToast('通知設定をブラウザー内へ保存しました');
    });

    notificationRoot.querySelector('[data-notification-remove]')?.addEventListener('click', () => {
        const index = notifications.findIndex((notification) => notification.id === selectedId);
        if (index < 0 || !window.confirm('このブラウザー内の通知設定を削除しますか？')) {
            return;
        }
        const removed = notifications.splice(index, 1)[0];
        if (!saveNotifications()) {
            notifications.splice(index, 0, removed);
            return;
        }
        selectedId = notifications[Math.min(index, notifications.length - 1)]?.id ?? null;
        renderNotifications();
        showToast('通知設定を削除しました');
    });

    notificationRoot.querySelector('[data-notification-test]')?.addEventListener('click', () => {
        const notification = selectedNotification();
        if (!notification || !notification.enabled) {
            showToast('有効な通知設定を選択してください');
            return;
        }
        const total = notificationEventTypes.filter((eventType) => notification.webhooks[eventType] !== '').length;
        showToast(total === 0
            ? '送信するWebhook URLがありません'
            : `${total}件のテスト送信をシミュレーションしました`);
    });

    const renderStreamerSelection = () => {
        if (!notificationStreamerList) {
            return;
        }
        const notification = selectedNotification();
        const streamers = loadStoredStreamers();
        notificationStreamerList.replaceChildren();
        if (!notification || streamers.length === 0) {
            notificationStreamerList.append(makeElement(
                'div',
                'streamer-selection-empty',
                '配信者画面で配信者を登録すると、ここから選択できます。',
            ));
            return;
        }
        streamers.forEach((streamer) => {
            const label = makeElement('label', 'streamer-selection-item');
            const checkbox = document.createElement('input');
            checkbox.type = 'checkbox';
            checkbox.name = 'streamerIds';
            checkbox.value = streamer.id;
            checkbox.checked = notification.streamerIds.includes(streamer.id);
            const details = makeElement('span');
            details.append(
                makeElement('strong', '', streamer.nameJa),
                makeElement('small', '', streamer.identifier),
            );
            label.append(checkbox, details);
            notificationStreamerList.append(label);
        });
    };

    notificationRoot.querySelector('[data-notification-streamers-open]')?.addEventListener('click', () => {
        renderStreamerSelection();
        const dialog = document.getElementById('notification-streamers-dialog');
        if (dialog instanceof HTMLDialogElement) {
            dialog.showModal();
        }
    });

    streamerForm?.addEventListener('submit', (event) => {
        event.preventDefault();
        const notification = selectedNotification();
        if (!notification || !(streamerForm instanceof HTMLFormElement)) {
            return;
        }
        const registeredIds = new Set(loadStoredStreamers().map((streamer) => streamer.id));
        const selectedIds = new FormData(streamerForm)
            .getAll('streamerIds')
            .map(String)
            .filter((streamerId) => registeredIds.has(streamerId));
        const previousIds = notification.streamerIds;
        notification.streamerIds = [...new Set(selectedIds)];
        notification.updatedAt = new Date().toISOString();
        if (!saveNotifications()) {
            notification.streamerIds = previousIds;
            return;
        }
        const dialog = streamerForm.closest('dialog');
        if (dialog instanceof HTMLDialogElement) {
            dialog.close();
        }
        renderNotifications();
        showToast('対象配信者をブラウザー内へ保存しました');
    });

    renderNotifications();
}

const settingsStorageKey = 'stream-notify-bot.admin-ui.settings.v1';
const settingsForm = document.querySelector('[data-settings-form]');

if (settingsForm instanceof HTMLFormElement) {
    const controls = Array.from(settingsForm.elements)
        .filter((control) => control instanceof HTMLInputElement && control.type === 'number');
    const defaults = Object.fromEntries(controls.map((control) => [control.name, control.valueAsNumber]));
    const saveState = document.querySelector('[data-settings-save-state]');
    const control = (name) => settingsForm.elements.namedItem(name);
    const numberValue = (name) => {
        const input = control(name);
        return input instanceof HTMLInputElement ? input.valueAsNumber : Number.NaN;
    };
    const applyValues = (values) => {
        controls.forEach((input) => {
            if (typeof values[input.name] === 'number' && Number.isFinite(values[input.name])) {
                input.value = values[input.name].toString();
            }
        });
    };
    const validateSettingsRelationships = () => {
        controls.forEach((input) => input.setCustomValidity(''));

        const initialBackoff = control('job_initial_backoff');
        const maxBackoff = control('job_max_backoff');
        if (maxBackoff instanceof HTMLInputElement
            && numberValue('job_max_backoff') < numberValue('job_initial_backoff')) {
            maxBackoff.setCustomValidity('最大再試行待機は初回再試行待機以上にしてください。');
        }

        const lease = control('job_lease_seconds');
        const maxRuntime = numberValue('job_max_runtime');
        const minimumLease = maxRuntime + Math.max(30, Math.ceil(maxRuntime * 0.2));
        if (lease instanceof HTMLInputElement && numberValue('job_lease_seconds') < minimumLease) {
            lease.setCustomValidity(`リース時間は最大実行時間を考慮して${minimumLease}秒以上にしてください。`);
        }

        ['youtube', 'twitch', 'twitcasting'].forEach((platform) => {
            const normal = control(`quota_${platform}_normal`);
            const normalValue = numberValue(`quota_${platform}_normal`);
            const reservedValue = numberValue(`quota_${platform}_reserved`);
            const allocationValue = numberValue(`quota_${platform}_allocation`);
            if (normal instanceof HTMLInputElement && normalValue + reservedValue > allocationValue) {
                normal.setCustomValidity('通常処理枠と優先予約枠の合計を割当量以下にしてください。');
            }
        });
    };
    const showInvalidSettingsPanel = () => {
        const invalid = settingsForm.querySelector(':invalid');
        const panel = invalid?.closest('[data-tab-panel]');
        if (panel instanceof HTMLElement) {
            document.querySelector(`[data-tab-target="${panel.dataset.tabPanel}"]`)?.click();
        }
        invalid?.reportValidity();
    };
    const readValues = () => Object.fromEntries(controls.map((input) => [input.name, input.valueAsNumber]));

    try {
        const stored = JSON.parse(window.localStorage.getItem(settingsStorageKey) ?? 'null');
        if (stored?.version === 1 && stored.values && typeof stored.updatedAt === 'string') {
            applyValues(stored.values);
            validateSettingsRelationships();
            if (settingsForm.checkValidity()) {
                if (saveState) {
                    saveState.textContent = `ブラウザー内へ保存済み: ${new Date(stored.updatedAt).toLocaleString('ja-JP')}`;
                }
            } else {
                applyValues(defaults);
                validateSettingsRelationships();
                if (saveState) {
                    saveState.textContent = '保存値が不正なため既定値を表示中';
                }
            }
        }
    } catch {
        applyValues(defaults);
        if (saveState) {
            saveState.textContent = '保存値を読み込めないため既定値を表示中';
        }
    }

    settingsForm.addEventListener('input', () => {
        validateSettingsRelationships();
        if (saveState) {
            saveState.textContent = '未保存の変更があります';
        }
    });

    settingsForm.addEventListener('submit', (event) => {
        event.preventDefault();
        validateSettingsRelationships();
        if (!settingsForm.checkValidity()) {
            showInvalidSettingsPanel();
            return;
        }
        const updatedAt = new Date().toISOString();
        try {
            window.localStorage.setItem(settingsStorageKey, JSON.stringify({
                version: 1,
                values: readValues(),
                updatedAt,
            }));
            if (saveState) {
                saveState.textContent = `ブラウザー内へ保存済み: ${new Date(updatedAt).toLocaleString('ja-JP')}`;
            }
            showToast('運用設定をブラウザー内へ保存しました');
        } catch {
            showToast('ブラウザー内へ保存できませんでした');
        }
    });

    document.querySelector('[data-settings-reset]')?.addEventListener('click', () => {
        applyValues(defaults);
        validateSettingsRelationships();
        if (saveState) {
            saveState.textContent = '既定値を読み込みました（未保存）';
        }
        showToast('安全な既定値を読み込みました');
    });
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
