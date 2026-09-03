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

document.querySelectorAll('[data-table-search]').forEach((input) => {
    input.addEventListener('input', () => {
        const table = document.getElementById(input.dataset.tableSearch);
        const query = input.value.trim().toLocaleLowerCase('ja');
        table?.querySelectorAll('[data-search-row]').forEach((row) => {
            row.hidden = !row.textContent.toLocaleLowerCase('ja').includes(query);
        });
    });
});

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
