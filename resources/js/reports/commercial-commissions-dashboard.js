document.addEventListener('DOMContentLoaded', () => {
    bindCommissionTabs();
    bindCommissionCommercialPicker();
    bindCommissionDetailTabs();
});

function bindCommissionTabs() {
    const triggers = [...document.querySelectorAll('[data-commission-tab-trigger]')];
    const panels = [...document.querySelectorAll('[data-commission-tab-panel]')];

    if (!triggers.length || !panels.length) {
        return;
    }

    const activate = (targetTab) => {
        triggers.forEach((trigger) => {
            trigger.classList.toggle('active', trigger.dataset.commissionTabTrigger === targetTab);
        });

        panels.forEach((panel) => {
            panel.classList.toggle('is-hidden', panel.dataset.commissionTabPanel !== targetTab);
        });
    };

    triggers.forEach((trigger) => {
        trigger.addEventListener('click', () => activate(trigger.dataset.commissionTabTrigger));
    });
}

function bindCommissionCommercialPicker() {
    const searchInput = document.getElementById('commissionCommercialSearch');
    const emptyState = document.getElementById('commissionCommercialEmpty');
    const options = [...document.querySelectorAll('[data-commercial-option]')];
    const panels = [...document.querySelectorAll('[data-commercial-panel]')];

    if (!options.length || !panels.length) {
        return;
    }

    const visibleOptions = () => options.filter((option) => !option.classList.contains('is-hidden'));

    const activateCommercial = (commercialId) => {
        let matched = false;

        options.forEach((option) => {
            const isActive = option.dataset.commercialId === commercialId && !option.classList.contains('is-hidden');
            option.classList.toggle('is-active', isActive);
            matched = matched || isActive;
        });

        panels.forEach((panel) => {
            const isActive = panel.dataset.commercialId === commercialId && matched;
            panel.classList.toggle('is-hidden', !isActive);
            panel.classList.toggle('is-active', isActive);
        });

        if (!matched) {
            panels.forEach((panel) => panel.classList.add('is-hidden'));
        }
    };

    const filterOptions = () => {
        const term = (searchInput?.value || '').trim().toLowerCase();

        options.forEach((option) => {
            const haystack = option.dataset.commercialSearch || '';
            option.classList.toggle('is-hidden', term !== '' && !haystack.includes(term));
        });

        const firstVisible = visibleOptions()[0];
        emptyState?.classList.toggle('is-hidden', Boolean(firstVisible));

        if (firstVisible) {
            activateCommercial(firstVisible.dataset.commercialId);
        } else {
            activateCommercial('');
        }
    };

    options.forEach((option) => {
        option.addEventListener('click', () => {
            activateCommercial(option.dataset.commercialId);
        });
    });

    searchInput?.addEventListener('input', filterOptions);
    filterOptions();
}

function bindCommissionDetailTabs() {
    const commercialPanels = [...document.querySelectorAll('[data-commercial-panel]')];

    commercialPanels.forEach((panel) => {
        const triggers = [...panel.querySelectorAll('[data-commercial-detail-tab-trigger]')];
        const detailPanels = [...panel.querySelectorAll('[data-commercial-detail-tab-panel]')];

        if (!triggers.length || !detailPanels.length) {
            return;
        }

        const activate = (targetTab) => {
            triggers.forEach((trigger) => {
                trigger.classList.toggle('active', trigger.dataset.commercialDetailTabTrigger === targetTab);
            });

            detailPanels.forEach((detailPanel) => {
                detailPanel.classList.toggle('is-hidden', detailPanel.dataset.commercialDetailTabPanel !== targetTab);
            });
        };

        triggers.forEach((trigger) => {
            trigger.addEventListener('click', () => {
                activate(trigger.dataset.commercialDetailTabTrigger);
            });
        });

        activate(triggers[0].dataset.commercialDetailTabTrigger);
    });
}
