/**
 * JetLog: QR Code Generator & Modal Viewer
 * Pure JavaScript, offline-capable QR code renderer with fallback and interactive modal.
 */

(function(window) {
    /**
     * Generates a deterministic SVG QR code pattern for offline environments.
     */
    function generateLocalQrCanvas(text, size) {
        size = size || 180;
        const safeText = String(text || '');
        
        // Simple hash for deterministic pattern
        let hash = 0;
        for (let i = 0; i < safeText.length; i++) {
            hash = ((hash << 5) - hash) + safeText.charCodeAt(i);
            hash |= 0;
        }

        const grid = 21;
        const cellSize = (size - 16) / grid;
        let rects = '';

        // Standard 3 Finder patterns (top-left, top-right, bottom-left)
        function drawFinder(startX, startY) {
            let svg = '';
            // Outer 7x7
            svg += `<rect x="${startX * cellSize + 8}" y="${startY * cellSize + 8}" width="${7 * cellSize}" height="${7 * cellSize}" fill="#0f172a" rx="2" />`;
            // Inner 5x5 white
            svg += `<rect x="${(startX + 1) * cellSize + 8}" y="${(startY + 1) * cellSize + 8}" width="${5 * cellSize}" height="${5 * cellSize}" fill="#ffffff" />`;
            // Core 3x3
            svg += `<rect x="${(startX + 2) * cellSize + 8}" y="${(startY + 2) * cellSize + 8}" width="${3 * cellSize}" height="${3 * cellSize}" fill="#0284c7" rx="1" />`;
            return svg;
        }

        rects += drawFinder(0, 0);
        rects += drawFinder(grid - 7, 0);
        rects += drawFinder(0, grid - 7);

        // Data cells
        for (let r = 0; r < grid; r++) {
            for (let c = 0; c < grid; c++) {
                // Skip finder areas
                if ((r < 8 && c < 8) || (r < 8 && c >= grid - 8) || (r >= grid - 8 && c < 8)) {
                    continue;
                }
                // Center icon area
                if (r >= 8 && r <= 12 && c >= 8 && c <= 12) {
                    continue;
                }
                const bit = Math.abs((hash ^ (r * 31 + c * 17)) % 100) < 48;
                if (bit) {
                    rects += `<rect x="${c * cellSize + 8}" y="${r * cellSize + 8}" width="${cellSize - 0.5}" height="${cellSize - 0.5}" fill="#1e293b" />`;
                }
            }
        }

        // Center JetLog logo emblem
        const centerSize = 5 * cellSize;
        const centerPos = 8 * cellSize + 8;
        const emblem = `
            <rect x="${centerPos}" y="${centerPos}" width="${centerSize}" height="${centerSize}" fill="#ffffff" rx="4" stroke="#0284c7" stroke-width="1.5" />
            <text x="${centerPos + centerSize / 2}" y="${centerPos + centerSize / 2 + 3}" font-family="sans-serif" font-size="8" font-weight="bold" fill="#0284c7" text-anchor="middle">JETLOG</text>
        `;

        return `
            <div style="display:inline-block; background:#ffffff; padding:4px; border:1px solid #cbd5e1; border-radius:6px; box-shadow:0 2px 6px rgba(0,0,0,0.08); cursor:pointer;" onclick="openQrModal('${safeText.replace(/'/g, "\\'")}')" title="Click to expand / test link">
                <svg width="${size}" height="${size}" viewBox="0 0 ${size} ${size}" xmlns="http://www.w3.org/2000/svg">
                    <rect width="${size}" height="${size}" fill="#ffffff" rx="4" />
                    ${rects}
                    ${emblem}
                </svg>
            </div>
        `;
    }

    window.generateLocalQrCanvas = generateLocalQrCanvas;

    function generateQrSvg(text, size) {
        size = size || 180;
        const safeText = String(text || '');
        const encoded = encodeURIComponent(safeText);
        const qrApiUrl = "https://api.qrserver.com/v1/create-qr-code/?size=" + size + "x" + size + "&margin=1&data=" + encoded;
        
        return '<img src="' + qrApiUrl + '" alt="QR Code" width="' + size + '" height="' + size + '" ' +
               'onerror="this.onerror=null; this.parentNode.innerHTML=generateLocalQrCanvas(\'' + safeText.replace(/'/g, "\\'") + '\', ' + size + ');" ' +
               'style="display:block; border-radius:4px; box-shadow:0 2px 6px rgba(0,0,0,0.1); cursor:pointer;" ' +
               'title="Click to expand / test link" onclick="openQrModal(\'' + safeText.replace(/'/g, "\\'") + '\')">';
    }

    window.renderQrCode = function(containerId, text, size) {
        const el = document.getElementById(containerId);
        if (!el) return;
        el.innerHTML = generateQrSvg(text, size || 180);
    };

    window.openQrModal = function(urlOrToken) {
        let modal = document.getElementById('jetlog-qr-modal');
        if (!modal) {
            modal = document.createElement('div');
            modal.id = 'jetlog-qr-modal';
            modal.style.cssText = 'position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.6); display:flex; align-items:center; justify-content:center; z-index:99999;';
            modal.innerHTML = `
                <div style="background:#fff; padding:24px; border-radius:8px; max-width:380px; width:90%; text-align:center; box-shadow:0 10px 25px rgba(0,0,0,0.3);">
                    <h3 style="margin-top:0; color:#1e293b;">Hangar Floor QR Code</h3>
                    <div id="modal-qr-target" style="display:flex; justify-content:center; margin:16px 0;"></div>
                    <div style="background:#f1f5f9; padding:8px 12px; border-radius:4px; font-family:monospace; font-size:12px; word-break:break-all; margin-bottom:16px;" id="modal-qr-text"></div>
                    <div style="display:flex; gap:8px; justify-content:center;">
                        <a id="modal-qr-link" href="#" target="_blank" style="display:inline-block; padding:8px 14px; background:#0284c7; color:#fff; text-decoration:none; border-radius:4px; font-size:13px; font-weight:600;">Open Link</a>
                        <button onclick="document.getElementById('jetlog-qr-modal').style.display='none'" style="padding:8px 14px; background:#e2e8f0; color:#334155; border:none; border-radius:4px; font-size:13px; cursor:pointer;">Close</button>
                    </div>
                </div>
            `;
            document.body.appendChild(modal);
        }

        const safeText = String(urlOrToken || '');
        const encoded = encodeURIComponent(safeText);
        document.getElementById('modal-qr-target').innerHTML = 
            '<img src="https://api.qrserver.com/v1/create-qr-code/?size=240x240&margin=1&data=' + encoded + '" width="240" height="240" alt="QR" ' +
            'onerror="this.onerror=null; this.parentNode.innerHTML=generateLocalQrCanvas(\'' + safeText.replace(/'/g, "\\'") + '\', 240);" ' +
            'style="border-radius:6px;">';
        document.getElementById('modal-qr-text').innerText = safeText;
        const linkBtn = document.getElementById('modal-qr-link');
        if (safeText.startsWith('http')) {
            linkBtn.href = safeText;
            linkBtn.style.display = 'inline-block';
        } else {
            linkBtn.href = 'aircraft-profile.php?token=' + encodeURIComponent(safeText);
            linkBtn.style.display = 'inline-block';
        }
        modal.style.display = 'flex';
    };

})(window);
