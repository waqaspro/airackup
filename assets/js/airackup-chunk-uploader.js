/**
 * Airackup - Resumable HTML5 Chunk Uploader
 * Slices any size file into 2MB chunks to bypass all web server upload limits.
 */
class AirackupChunkUploader {
    constructor(config) {
        this.chunkSize = config.chunkSize || (2 * 1024 * 1024); // 2MB default
        this.endpoint = config.endpoint;
        this.nonce = config.nonce;
    }

    upload(file, onProgress, onComplete, onError) {
        const totalSize = file.size;
        const totalChunks = Math.ceil(totalSize / this.chunkSize);
        const uploadId = 'up_' + Date.now() + '_' + Math.random().toString(36).substring(2, 9);
        let currentChunkIndex = 0;
        let retryCount = 0;
        const maxRetries = 5;

        const uploadNextChunk = () => {
            if (currentChunkIndex >= totalChunks) {
                if (typeof onComplete === 'function') {
                    onComplete({ uploadId, filename: file.name, totalSize });
                }
                return;
            }

            const start = currentChunkIndex * this.chunkSize;
            const end = Math.min(start + this.chunkSize, totalSize);
            const chunk = file.slice(start, end);

            const formData = new FormData();
            formData.append('action', 'airackup_chunk_upload');
            formData.append('nonce', this.nonce);
            formData.append('upload_id', uploadId);
            formData.append('chunk_index', currentChunkIndex);
            formData.append('total_chunks', totalChunks);
            formData.append('filename', file.name);
            formData.append('chunk_data', chunk);

            fetch(this.endpoint, {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    retryCount = 0;
                    currentChunkIndex++;
                    const percent = Math.round((end / totalSize) * 100);
                    if (typeof onProgress === 'function') {
                        onProgress(percent, end, totalSize, currentChunkIndex, totalChunks);
                    }
                    uploadNextChunk();
                } else {
                    handleError(data.data ? data.data.message : 'Upload chunk rejected');
                }
            })
            .catch(err => {
                handleError(err.message || 'Network error during chunk upload');
            });
        };

        const handleError = (errMsg) => {
            if (retryCount < maxRetries) {
                retryCount++;
                const delay = Math.pow(2, retryCount) * 500;
                setTimeout(uploadNextChunk, delay);
            } else {
                if (typeof onError === 'function') {
                    onError(errMsg);
                }
            }
        };

        uploadNextChunk();
    }
}

if (typeof window !== 'undefined') {
    window.AirackupChunkUploader = AirackupChunkUploader;
}
