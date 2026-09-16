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

  it('treats warning at 100 percent as warning, not success', () => {
    const warning = window.bjlgTaskProgress.interpret({
      progress: 100,
      status: 'warning',
      status_text: 'Envois distants partiels'
    });

    expect(warning.done).toBe(true);
    expect(warning.outcome).toBe('warning');
    expect(warning.message).toContain('Envois distants partiels');
  });

  it('stops polling after repeated failures or timeout', () => {
    expect(window.bjlgTaskProgress.shouldStopPolling(null, 5, 1000)).toBe(true);
    expect(window.bjlgTaskProgress.shouldStopPolling(null, 1, 1000, { maxFailures: 5, timeoutMs: 500 })).toBe(true);
    expect(window.bjlgTaskProgress.shouldStopPolling(null, 1, 100, { maxFailures: 5, timeoutMs: 5000 })).toBe(false);
  });

  it('treats a running timeout as timeout, not success', () => {
    const running = window.bjlgTaskProgress.interpret({
      progress: 41,
      status: 'running',
      status_text: 'Restauration des fichiers'
    });

    expect(running.done).toBe(false);
    expect(running.outcome).toBe('running');
    expect(window.bjlgTaskProgress.getPollingStopReason(running, 0, 45 * 60 * 1000)).toBe('timeout');
    expect(window.bjlgTaskProgress.getPollingStopReason(running, 0, 1000)).toBeNull();
    expect(window.bjlgTaskProgress.getPollingStopReason({
      done: true,
      outcome: 'success',
      message: 'Terminé',
      progress: 100
    }, 0, 45 * 60 * 1000)).toBe('success');
  });
});
