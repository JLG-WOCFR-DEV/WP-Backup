describe('Backup list action buttons', () => {
  let ajaxCalls;

  function flushListLoad() {
    const listCall = ajaxCalls.find((call) => {
      const method = (call.options.method || call.options.type || 'GET').toUpperCase();
      return method === 'GET';
    });
    expect(ajaxCalls.length).toBeGreaterThan(0);
    expect(listCall).toBeDefined();
    listCall.jqXHR._done({
      backups: [
        {
          filename: 'backup-db-test.zip',
          type: 'full',
          size: 299651,
          created_at: '2026-09-15 16:00:32',
          components: ['db']
        }
      ],
      pagination: { total: 1, pages: 1 }
    });
    if (typeof listCall.jqXHR._always === 'function') {
      listCall.jqXHR._always();
    }
  }

  beforeEach(() => {
    jest.resetModules();
    ajaxCalls = [];

    document.body.innerHTML = `
      <div id="bjlg-backup-list-section" data-default-page="1" data-default-per-page="10">
        <div id="bjlg-backup-list-feedback" class="notice" style="display:none;"></div>
        <select id="bjlg-backup-filter-type"><option value="all" selected>all</option></select>
        <select id="bjlg-backup-per-page"><option value="10" selected>10</option></select>
        <button type="button" id="bjlg-backup-refresh">Actualiser</button>
        <div id="bjlg-backup-summary"></div>
        <table><tbody id="bjlg-backup-table-body"></tbody></table>
        <div id="bjlg-backup-pagination"></div>
      </div>
      <form id="bjlg-restore-form">
        <input type="checkbox" name="create_backup_before_restore" value="1" checked>
        <input type="password" id="bjlg-restore-password" value="">
      </form>
      <div id="bjlg-restore-status" style="display:none;">
        <div id="bjlg-restore-progress-bar"></div>
        <p id="bjlg-restore-status-text"></p>
      </div>
    `;

    window.bjlg_ajax = {
      ajax_url: '/wp-admin/admin-ajax.php',
      nonce: 'test-nonce',
      rest_root: 'https://example.com/wp-json/',
      rest_namespace: 'backup-jlg/v1',
      rest_backups: 'https://example.com/wp-json/backup-jlg/v1/backups'
    };
    global.bjlg_ajax = window.bjlg_ajax;
    window.bjlgAdmin = {
      setActiveSection: jest.fn()
    };
    window.confirm = jest.fn(() => true);

    const ajaxMock = jest.fn((options) => {
      const jqXHR = {
        _done: null,
        _fail: null,
        _always: null,
        done(cb) {
          this._done = cb;
          return this;
        },
        fail(cb) {
          this._fail = cb;
          return this;
        },
        always(cb) {
          this._always = cb;
          return this;
        }
      };
      ajaxCalls.push({ options, jqXHR });
      return jqXHR;
    });

    const realJq = global.__BJLG_JQUERY || require('jquery');
    realJq.ajax = ajaxMock;
    $.ajax = ajaxMock;
    jQuery.ajax = ajaxMock;

    require('../admin-backup.js');
    flushListLoad();
  });

  it('renders download, delete and restore buttons', () => {
    expect(document.querySelectorAll('.bjlg-download-button')).toHaveLength(1);
    expect(document.querySelectorAll('.bjlg-delete-button')).toHaveLength(1);
    expect(document.querySelectorAll('.bjlg-restore-button')).toHaveLength(1);
  });

  it('sends bjlg_prepare_download then starts the file download', () => {
    const anchorClick = jest.spyOn(window.HTMLAnchorElement.prototype, 'click').mockImplementation(() => {});

    document.querySelector('.bjlg-download-button').click();

    const downloadCall = ajaxCalls.find((call) => call.options.data && call.options.data.action === 'bjlg_prepare_download');
    expect(downloadCall).toBeDefined();
    expect(downloadCall.options.url).toBe('/wp-admin/admin-ajax.php');
    expect(downloadCall.options.data.filename).toBe('backup-db-test.zip');
    expect(downloadCall.options.data.nonce).toBe('test-nonce');

    downloadCall.jqXHR._done({
      success: true,
      data: {
        download_url: '/wp-admin/admin-ajax.php?action=bjlg_download&token=abc123',
        token: 'abc123'
      }
    });
    if (typeof downloadCall.jqXHR._always === 'function') {
      downloadCall.jqXHR._always();
    }

    expect(anchorClick).toHaveBeenCalled();
    const clicked = anchorClick.mock.instances[0];
    expect(clicked.getAttribute('href')).toContain('action=bjlg_download');
    expect(clicked.getAttribute('href')).toContain('token=abc123');
    expect(clicked.getAttribute('download')).toBe('backup-db-test.zip');

    anchorClick.mockRestore();
  });

  it('sends bjlg_delete_backup after confirmation', () => {
    document.querySelector('.bjlg-delete-button').click();

    expect(window.confirm).toHaveBeenCalled();
    const deleteCall = ajaxCalls.find((call) => call.options.data && call.options.data.action === 'bjlg_delete_backup');
    expect(deleteCall).toBeDefined();
    expect(deleteCall.options.data.filename).toBe('backup-db-test.zip');
  });

  it('sends bjlg_run_restore from the list restore button', () => {
    document.querySelector('.bjlg-restore-button').click();

    expect(window.confirm).toHaveBeenCalled();
    expect(window.bjlgAdmin.setActiveSection).toHaveBeenCalledWith('restore', true);

    const restoreCall = ajaxCalls.find((call) => call.options.data && call.options.data.action === 'bjlg_run_restore');
    expect(restoreCall).toBeDefined();
    expect(restoreCall.options.data.filename).toBe('backup-db-test.zip');
  });
});
