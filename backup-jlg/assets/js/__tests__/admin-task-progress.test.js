describe('bjlgTaskProgress', () => {
  beforeEach(() => {
    jest.resetModules();
    document.body.innerHTML = '';
    window.bjlg_ajax = {
      ajax_url: '/ajax',
      nonce: 'nonce'
    };
    require('../admin-backup.js');
  });

  it('treats progress 100 without complete as an error', () => {
    const result = window.bjlgTaskProgress.interpret({
      progress: 100,
      status: 'running',
      status_text: 'Toujours en cours'
    });

    expect(result.done).toBe(false);
    expect(result.outcome).toBe('running');

    const stuck = window.bjlgTaskProgress.interpret({
      progress: 100,
      status: 'mystery',
      status_text: 'Inconnu'
    });

    expect(stuck.done).toBe(true);
    expect(stuck.outcome).toBe('error');
  });

  it('requires status complete for success', () => {
    const success = window.bjlgTaskProgress.interpret({
      progress: 100,
      status: 'complete',
      status_text: 'Sauvegarde terminée avec succès !'
    });

    expect(success.done).toBe(true);
    expect(success.outcome).toBe('success');
    expect(success.message).toContain('Sauvegarde terminée');

    const failure = window.bjlgTaskProgress.interpret({
      progress: 100,
      status: 'error',
      status_text: 'Disque plein'
    });

    expect(failure.outcome).toBe('error');
    expect(failure.message).toContain('Disque plein');
  });

  it('stops polling after repeated failures or timeout', () => {
    expect(window.bjlgTaskProgress.shouldStopPolling(null, 5, 1000)).toBe(true);
    expect(window.bjlgTaskProgress.shouldStopPolling(null, 1, 1000, { maxFailures: 5, timeoutMs: 500 })).toBe(true);
    expect(window.bjlgTaskProgress.shouldStopPolling(null, 1, 100, { maxFailures: 5, timeoutMs: 5000 })).toBe(false);
  });
});
