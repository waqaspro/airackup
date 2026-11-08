/**
 * Airackup - Interactive Restore Worker Loop
 * Orchestrates micro-batch restore and serialized migration steps.
 */
class AirackupRestoreWorker {
    constructor(config) {
        this.endpoint = config.endpoint;
        this.nonce = config.nonce;
        this.sessionId = null;
        this.token = null;
        this.isCancelled = false;
    }

    start(params, onTick, onComplete, onError) {
        this.isCancelled = false;

        const initData = new URLSearchParams();
        initData.append('action', 'airackup_start_restore');
        initData.append('nonce', this.nonce);
        if (params.backup_file) initData.append('backup_file', params.backup_file);
        if (params.upload_id) initData.append('upload_id', params.upload_id);
        if (params.search_url) initData.append('search_url', params.search_url);
        if (params.replace_url) initData.append('replace_url', params.replace_url);

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
                if (typeof onError === 'function') onError(data.data ? data.data.message : 'Restore start failed');
            }
        })
        .catch(err => {
            if (typeof onError === 'function') onError(err.message || 'Network error on restore start');
        });
    }

    runLoop(onTick, onComplete, onError) {
        if (this.isCancelled) return;

        const stepData = new URLSearchParams();
        stepData.append('action', 'airackup_step_restore');
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
                if (typeof onError === 'function') onError(resData.data ? resData.data.message : 'Restore step failed');
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
                setTimeout(() => this.runLoop(onTick, onComplete, onError), 100);
            }
        })
        .catch(err => {
            setTimeout(() => this.runLoop(onTick, onComplete, onError), 2000);
        });
    }

    cancel() {
        this.isCancelled = true;
    }
}

if (typeof window !== 'undefined') {
    window.AirackupRestoreWorker = AirackupRestoreWorker;
}
