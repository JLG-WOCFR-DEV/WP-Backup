describe('Admin dashboard remote storage rendering', () => {
  beforeEach(() => {
    jest.resetModules();
    document.body.innerHTML = `
      <div id="bjlg-dashboard-live-region"></div>
      <div class="bjlg-dashboard-overview" data-bjlg-dashboard='{}'>
        <div data-metric="remote-storage">
          <p data-field="remote_storage_refresh"></p>
          <p data-field="remote_storage_connected"></p>
          <p data-field="remote_storage_caption"></p>
          <ul data-field="remote_storage_list"></ul>
        </div>
      </div>
    `;
  });

  it('displays forecast and days to threshold labels', () => {
    const metrics = {
      summary: {},
      storage: {
        remote_destinations: [
          {
            id: 'alpha',
            name: 'Alpha Cloud',
            connected: true,
            errors: [],
            used_human: '2 GB',
            quota_human: '5 GB',
            free_human: '3 GB',
            utilization_ratio: 0.4,
            ratio: 0.4,
            latency_ms: 120,
            forecast_label: 'Croissance de 1 GB par jour',
            days_to_threshold: 2,
            days_to_threshold_label: 'Saturation estimée dans 2 jours',
            projection_intent: 'warning'
          }
        ],
        remote_last_refreshed_formatted: 'Aujourd\'hui',
        remote_last_refreshed_relative: 'il y a 1 minute',
        remote_refresh_stale: false,
        remote_warning_threshold: 80,
        remote_threshold: 0.8
      },
      queues: {},
      alerts: [],
      reliability: {},
      onboarding: []
    };

    const overview = document.querySelector('.bjlg-dashboard-overview');
    const serialized = JSON.stringify(metrics);
    overview.setAttribute('data-bjlg-dashboard', serialized);

    Object.defineProperty(document, 'readyState', {
      configurable: true,
      get: () => 'complete'
    });

    require('../admin-dashboard.js');

    if (window.jQuery && typeof window.jQuery.fn.ready === 'function') {
      window.jQuery(document).triggerHandler('ready');
    }

    document.dispatchEvent(new window.Event('DOMContentLoaded', { bubbles: true }));
    window.dispatchEvent(new window.Event('load'));

    if (window.bjlgDashboard && typeof window.bjlgDashboard.updateMetrics === 'function') {
      window.bjlgDashboard.updateMetrics(metrics);
    }

    const listItem = document.querySelector('[data-field="remote_storage_list"] .bjlg-card__list-item');
    expect(listItem).not.toBeNull();
    expect(listItem.textContent).toContain('Saturation estimée dans 2 jours');
    expect(listItem.dataset.intent).toBe('warning');
  });
});
