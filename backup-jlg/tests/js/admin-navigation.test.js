describe('Admin navigation accessibility events', () => {
  beforeEach(() => {
    jest.resetModules();
    document.body.innerHTML = `
      <div id="bjlg-main-content" class="wrap bjlg-wrap" data-active-section="monitoring"></div>
      <div class="bjlg-admin-shell" data-active-section="monitoring">
        <aside class="bjlg-admin-shell__sidebar" id="bjlg-shell-sidebar">
          <a class="bjlg-sidebar__nav-link is-active" data-section="monitoring" href="#monitoring">Monitoring</a>
          <a class="bjlg-sidebar__nav-link" data-section="settings" href="#settings">Settings</a>
        </aside>
        <div class="bjlg-admin-shell__main">
          <div id="bjlg-section-announcer"></div>
          <div id="bjlg-admin-app" data-active-section="monitoring">
            <div class="bjlg-admin-app__panels">
              <section class="bjlg-shell-section" data-section="monitoring" aria-hidden="false" tabindex="0"></section>
              <section class="bjlg-shell-section" data-section="settings" aria-hidden="true" hidden="hidden"></section>
            </div>
          </div>
        </div>
      </div>
    `;

    window.bjlg_ajax = {
      ajax_url: '/ajax',
      nonce: 'nonce',
      rest_nonce: 'rest-nonce',
      rest_root: 'https://example.com/wp-json/',
      rest_namespace: 'backup-jlg/v1',
      chart_url: '/chart.js',
      modules: {},
      section_modules: {
        monitoring: [],
        settings: []
      },
      tab_modules: {
        monitoring: [],
        settings: []
      },
      active_section: 'monitoring'
    };

    require('../../assets/js/admin.js');
  });

  it('dispatches a custom event when the section changes programmatically', () => {
    const handler = jest.fn();
    document.addEventListener('bjlg:section-activated', (event) => handler(event.detail));

    window.bjlgAdmin.setActiveSection('settings', true);

    expect(handler).toHaveBeenCalled();
    expect(handler.mock.calls[0][0].section).toBe('settings');

    const settingsPanel = document.querySelector('.bjlg-shell-section[data-section="settings"]');
    expect(settingsPanel.hasAttribute('hidden')).toBe(false);
  });

  it('updates the active section when clicking a sidebar link', () => {
    const handler = jest.fn();
    document.addEventListener('bjlg:section-activated', handler);

    const link = document.querySelector('.bjlg-sidebar__nav-link[data-section="settings"]');
    link.dispatchEvent(new window.MouseEvent('click', { bubbles: true, cancelable: true }));

    expect(handler).toHaveBeenCalled();
    expect(window.bjlgAdmin.getActiveSection()).toBe('settings');
  });
});
