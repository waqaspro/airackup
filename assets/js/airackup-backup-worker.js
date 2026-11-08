/**
 * Airackup - Interactive Backup Worker Loop
 * Orchestrates micro-batch AJAX steps for zero-timeout execution.
 */
class AirackupBackupWorker {
    constructor(config) {
        this.endpoint = config.endpoint;
        this.nonce = config.nonce;
        this.sessionId = null;
        this.token = null;
        this.isCancelled = false;
    }

    start(options, onTick, onComplete, onError) {
        this.isCancelled = false;

        // Step 1: Initialize session
        const initData = new URLSearchParams();
        initData.append('action', 'airackup_start_backup');
        initData.append('nonce', this.nonce);
        initData.append('type', options.type || 'full');
        if (options.remote) initData.append('remote', options.remote);
        if (options.exclude) initData.append('exclude', JSON.stringify(options.exclude));

        fetch(this.endpoint, {
            method: 'POST',
            body: initData,
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' }
        })
        .then(res => res.json())
        .then(data => {
            if (data.success && data.data) {
                this.sessionId = data.data.session_id;
                this.token = data.data.token;
                this.runLoop(onTick, onComplete, onError);
            } else {
                if (typeof onError === 'function') onError(data.data ? data.data.message : 'Initialization failed');
            }
        })
        .catch(err => {
            if (typeof onError === 'function') onError(err.message || 'Network error on backup start');
        });
    }

    runLoop(onTick, onComplete, onError) {
        if (this.isCancelled) return;

        const stepData = new URLSearchParams();
        stepData.append('action', 'airackup_step_backup');
        stepData.append('nonce', this.nonce);
        stepData.append('session_id', this.sessionId);
        stepData.append('token', this.token);

        fetch(this.endpoint, {
            method: 'POST',
            body: stepData,
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' }
        })
        .then(res => res.json())
        .then(resData => {
            if (!resData.success) {
                if (typeof onError === 'function') onError(resData.data ? resData.data.message : 'Backup step failed');
                return;
            }

            const state = resData.data;
            if (typeof onTick === 'function') {
                onTick(state);
            }

            if (state.phase === 'complete' || state.status === 'complete') {
                if (typeof onComplete === 'function') {
                    onComplete(state);
                }
            } else {
                // Next tick without delay
                setTimeout(() => this.runLoop(onTick, onComplete, onError), 100);
            }
        })
        .catch(err => {
            // Auto retry on temporary glitch
            setTimeout(() => this.runLoop(onTick, onComplete, onError), 2000);
        });
    }

    cancel() {
        this.isCancelled = true;
    }
}

if (typeof window !== 'undefined') {
    window.AirackupBackupWorker = AirackupBackupWorker;
}
