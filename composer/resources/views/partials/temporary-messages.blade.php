{{-- Temporary credential expiry messages for current user --}}
<div id="agTemporaryMessages" class="ag-messages-container"></div>

<style>
.ag-messages-container {
    position: fixed;
    top: 20px;
    right: 20px;
    z-index: 9999;
    max-width: 400px;
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.ag-message {
    background: white;
    border-left: 4px solid #3b82f6;
    border-radius: 6px;
    padding: 16px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 12px;
    animation: slideInRight 0.3s ease-out;
}

.ag-message.warn {
    border-left-color: #f59e0b;
    background-color: #fffbeb;
}

.ag-message.error {
    border-left-color: #ef4444;
    background-color: #fef2f2;
}

.ag-message.info {
    border-left-color: #3b82f6;
    background-color: #eff6ff;
}

.ag-message-icon {
    flex-shrink: 0;
    font-size: 18px;
}

.ag-message.warn .ag-message-icon { color: #f59e0b; }
.ag-message.error .ag-message-icon { color: #ef4444; }
.ag-message.info .ag-message-icon { color: #3b82f6; }

.ag-message-content {
    flex: 1;
}

.ag-message-title {
    font-weight: 600;
    font-size: 14px;
    margin-bottom: 4px;
}

.ag-message.warn .ag-message-title { color: #b45309; }
.ag-message.error .ag-message-title { color: #dc2626; }
.ag-message.info .ag-message-title { color: #1e40af; }

.ag-message-text {
    font-size: 13px;
    line-height: 1.4;
}

.ag-message.warn .ag-message-text { color: #92400e; }
.ag-message.error .ag-message-text { color: #7f1d1d; }
.ag-message.info .ag-message-text { color: #1e3a8a; }

.ag-message-close {
    flex-shrink: 0;
    background: none;
    border: none;
    color: #6b7280;
    cursor: pointer;
    font-size: 18px;
    padding: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    width: 24px;
    height: 24px;
}

.ag-message-close:hover {
    color: #374151;
}

@keyframes slideInRight {
    from {
        transform: translateX(400px);
        opacity: 0;
    }
    to {
        transform: translateX(0);
        opacity: 1;
    }
}

@media (max-width: 640px) {
    .ag-messages-container {
        max-width: calc(100vw - 20px);
        right: 10px;
        left: 10px;
    }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const container = document.getElementById('agTemporaryMessages');

    // Fetch messages
    fetch('/api/v1/temporary-messages', {
        method: 'GET',
        headers: {
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(res => res.json())
    .then(data => {
        if (data.messages && data.messages.length > 0) {
            data.messages.forEach(msg => {
                const messageEl = createMessageElement(msg);
                container.appendChild(messageEl);
            });
        }
    })
    .catch(err => console.error('Failed to load temporary messages:', err));

    function createMessageElement(msg) {
        const div = document.createElement('div');
        div.className = `ag-message ${msg.severity || 'info'}`;

        const iconMap = {
            'error': 'fa-circle-exclamation',
            'warn': 'fa-triangle-exclamation',
            'info': 'fa-circle-info'
        };

        const icon = iconMap[msg.severity] || 'fa-circle-info';

        div.innerHTML = `
            <span class="ag-message-icon"><i class="fas ${icon}"></i></span>
            <div class="ag-message-content">
                <div class="ag-message-title">
                    ${escapeHtml(msg.credential_type === 'service_account' ? 'Service Account Key' : 'API Key')}
                </div>
                <div class="ag-message-text">${escapeHtml(msg.message)}</div>
            </div>
            <button type="button" class="ag-message-close" title="Dismiss" onclick="this.parentElement.remove(); markMessageRead(${msg.id});">
                <i class="fas fa-xmark"></i>
            </button>
        `;

        return div;
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    window.markMessageRead = function(messageId) {
        fetch(`/api/v1/temporary-messages/${messageId}/read`, {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
            }
        })
        .catch(err => console.error('Failed to mark message as read:', err));
    };
});
</script>
