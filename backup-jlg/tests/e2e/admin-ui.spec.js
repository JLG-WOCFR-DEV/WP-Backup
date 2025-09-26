const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const adminScript = fs.readFileSync(
  path.resolve(__dirname, '../../assets/js/admin.js'),
  'utf8'
);

const adminHtml = `<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="utf-8" />
  <title>Backup JLG UI Testbed</title>
</head>
<body>
  <section>
    <form id="bjlg-backup-creation-form">
      <label>
        <input type="checkbox" name="backup_components[]" value="db" />Base de données
      </label>
      <label>
        <input type="checkbox" name="backup_components[]" value="uploads" />Uploads
      </label>
      <label>
        <input type="checkbox" name="encrypt_backup" value="1" />Chiffrer
      </label>
      <label>
        <input type="checkbox" name="incremental_backup" value="1" />Incrémental
      </label>
      <button id="start-backup" type="submit">Lancer une sauvegarde</button>
    </form>
    <div id="bjlg-backup-progress-area" style="display:none;">
      <p id="bjlg-backup-status-text"></p>
      <div id="bjlg-backup-progress-bar" style="width:0%">0%</div>
    </div>
    <div id="bjlg-backup-debug-wrapper" style="display:none;">
      <pre id="bjlg-backup-ajax-debug"></pre>
    </div>
  </section>
  <section>
    <form id="bjlg-restore-form">
      <input id="bjlg-restore-file-input" name="restore_file" type="file" />
      <label>
        <input type="checkbox" name="create_backup_before_restore" value="1" />Créer un point de restauration
      </label>
      <button id="start-restore" type="submit">Lancer la restauration</button>
    </form>
    <div id="bjlg-restore-status" style="display:none;">
      <p id="bjlg-restore-status-text"></p>
      <div id="bjlg-restore-progress-bar" style="width:0%">0%</div>
    </div>
  </section>
</body>
</html>`;

async function mountAdminPage(page) {
  await page.addInitScript(() => {
    window.bjlg_ajax = {
      ajax_url: 'https://example.test/wp-admin/admin-ajax.php',
      nonce: 'test-nonce',
    };
    window.ajaxurl = window.bjlg_ajax.ajax_url;
    window.__reloaded = false;
    window.location.reload = () => {
      window.__reloaded = true;
    };

    const originalSetInterval = window.setInterval.bind(window);
    window.setInterval = (fn, delay, ...args) =>
      originalSetInterval(fn, Math.min(delay ?? 0, 50), ...args);

    const originalSetTimeout = window.setTimeout.bind(window);
    window.setTimeout = (fn, delay, ...args) =>
      originalSetTimeout(fn, Math.min(delay ?? 0, 50), ...args);
  });

  await page.setContent(adminHtml, { waitUntil: 'domcontentloaded' });
  await page.addScriptTag({ path: require.resolve('jquery/dist/jquery.js') });
  await page.waitForFunction(() => typeof window.jQuery !== 'undefined');
  await page.addScriptTag({ content: adminScript });
  await page.waitForFunction(() =>
    typeof window.jQuery !== 'undefined' &&
    window.jQuery('#bjlg-backup-creation-form').length === 1
  );
}

test.beforeEach(async ({ page }) => {
  await mountAdminPage(page);
});

test('backup flow exposes progress, debug output, and reload state', async ({ page }) => {
  let progressCalls = 0;

  await page.route('**/admin-ajax.php', async route => {
    const request = route.request();
    const body = request.postData() || '';

    if (body.includes('bjlg_start_backup_task')) {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ success: true, data: { task_id: 'task-123' } }),
      });
      return;
    }

    if (body.includes('bjlg_check_backup_progress')) {
      progressCalls += 1;
      let payload;

      if (progressCalls === 1) {
        payload = {
          success: true,
          data: {
            progress: 18.2,
            status: 'running',
            status_text: 'Sauvegarde en cours...',
          },
        };
      } else if (progressCalls === 2) {
        payload = {
          success: true,
          data: {
            progress: 76.4,
            status: 'running',
            status_text: 'Compression finale...',
          },
        };
      } else {
        payload = {
          success: true,
          data: {
            progress: 100,
            status: 'complete',
            status_text: 'Archive prête.',
          },
        };
      }

      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify(payload),
      });
      return;
    }

    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ success: false, data: { message: 'Action inattendue' } }),
    });
  });

  await page.check('input[name="backup_components[]"][value="db"]');
  await page.click('#start-backup');

  const progressArea = page.locator('#bjlg-backup-progress-area');
  await expect(progressArea).toBeVisible();

  const statusText = page.locator('#bjlg-backup-status-text');
  await expect(statusText).toHaveText('Initialisation...');
  await expect(statusText).toHaveText(/Sauvegarde en cours/);
  await expect(page.locator('#bjlg-backup-progress-bar')).toHaveText('18.2%');

  await expect(statusText).toHaveText(/Compression finale/);

  const debugOutput = page.locator('#bjlg-backup-ajax-debug');
  await expect(debugOutput).toContainText('bjlg_start_backup_task');
  await expect(debugOutput).toContainText('--- 2. SUIVI DE LA PROGRESSION ---');

  await expect(statusText).toHaveText('✔️ Terminé ! La page va se recharger.');
  await expect(page.locator('#bjlg-backup-progress-bar')).toContainText('100');
  await expect.poll(() => page.evaluate(() => window.__reloaded)).toBeTruthy();
});

test('restore flow surfaces progress states for encrypted archives', async ({ page }) => {
  let restorePolls = 0;

  await page.route('**/admin-ajax.php', async route => {
    const request = route.request();
    const body = request.postData() || '';

    if (body.includes('bjlg_upload_restore_file')) {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ success: true, data: { filename: 'upload.zip.enc' } }),
      });
      return;
    }

    if (body.includes('bjlg_run_restore')) {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ success: true, data: { task_id: 'restore-task' } }),
      });
      return;
    }

    if (body.includes('bjlg_check_restore_progress')) {
      restorePolls += 1;
      let payload;

      if (restorePolls === 1) {
        payload = {
          success: true,
          data: {
            progress: 20,
            status: 'running',
            status_text: "Déchiffrement de l'archive...",
          },
        };
      } else if (restorePolls === 2) {
        payload = {
          success: true,
          data: {
            progress: 74.8,
            status: 'running',
            status_text: 'Restauration des fichiers...',
          },
        };
      } else {
        payload = {
          success: true,
          data: {
            progress: 100,
            status: 'complete',
            status_text: 'Restauration terminée avec succès !',
          },
        };
      }

      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify(payload),
      });
      return;
    }

    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ success: false, data: { message: 'Action inattendue' } }),
    });
  });

  await page.setInputFiles('#bjlg-restore-file-input', {
    name: 'secure-backup.zip.enc',
    mimeType: 'application/zip',
    buffer: Buffer.from('dummy'),
  });

  await page.check('input[name="create_backup_before_restore"]');
  await page.click('#start-restore');

  const statusWrapper = page.locator('#bjlg-restore-status');
  await expect(statusWrapper).toBeVisible();

  const statusText = page.locator('#bjlg-restore-status-text');
  await expect(statusText).toHaveText('Téléversement du fichier en cours...');
  await expect(statusText).toHaveText('Fichier téléversé. Préparation de la restauration...');
  await expect(statusText).toHaveText(/Déchiffrement de l'archive/);
  await expect(statusText).toHaveText(/Restauration des fichiers/);
  await expect(statusText).toHaveText('Restauration terminée avec succès !');
  await expect(page.locator('#bjlg-restore-progress-bar')).toContainText('100');
});

test('backup flow reports API failures to the operator', async ({ page }) => {
  await page.route('**/admin-ajax.php', async route => {
    const body = route.request().postData() || '';

    if (body.includes('bjlg_start_backup_task')) {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ success: false, data: { message: 'Service indisponible' } }),
      });
      return;
    }

    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ success: false, data: { message: 'Action inattendue' } }),
    });
  });

  await page.check('input[name="backup_components[]"][value="db"]');
  await page.click('#start-backup');

  const statusText = page.locator('#bjlg-backup-status-text');
  await expect(statusText).toHaveText('Erreur lors du lancement : Service indisponible');
  await expect(page.locator('#start-backup')).toBeEnabled();
  await expect(page.locator('#bjlg-backup-ajax-debug')).toContainText('Service indisponible');
});
