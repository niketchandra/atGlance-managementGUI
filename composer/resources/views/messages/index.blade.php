@extends('app')

@section('title', 'Temporary Messages')

@section('content')
<div class="ag-container">
    <div class="ag-card">
        <div class="ag-card-header">
            <h1>Temporary Messages</h1>
            <p class="ag-text-muted">Credential expiry alerts (auto-expire after 12 hours)</p>
        </div>

        <div id="messagesContainer" class="ag-messages-list">
            <div class="ag-empty-state">
                <i class="fas fa-inbox"></i>
                <p>No messages</p>
            </div>
        </div>
    </div>
</div>

<style>
.ag-messages-list {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.ag-message-item {
    display: flex;
    gap: 16px;
    padding: 16px;
    border: 1px solid #e5e7eb;
    border-radius: 6px;
    background: white;
    align-items: flex-start;
}

.ag-message-item.info {
    border-left: 4px solid #3b82f6;
    background-color: #eff6ff;
}

.ag-message-item.warn {
    border-left: 4px solid #f59e0b;
    background-color: #fffbeb;
}

.ag-message-item.error {
    border-left: 4px solid #ef4444;
    background-color: #fef2f2;
}

.ag-message-icon {
    flex-shrink: 0;
    font-size: 20px;
    margin-top: 2px;
}

.ag-message-item.info .ag-message-icon { color: #3b82f6; }
.ag-message-item.warn .ag-message-icon { color: #f59e0b; }
.ag-message-item.error .ag-message-icon { color: #ef4444; }

.ag-message-content {
    flex: 1;
    min-width: 0;
}

.ag-message-title {
    font-weight: 600;
    font-size: 14px;
    margin-bottom: 4px;
}

.ag-message-item.info .ag-message-title { color: #1e40af; }
.ag-message-item.warn .ag-message-title { color: #b45309; }
.ag-message-item.error .ag-message-title { color: #dc2626; }

.ag-message-text {
    font-size: 13px;
    line-height: 1.5;
    margin-bottom: 8px;
}

.ag-message-item.info .ag-message-text { color: #1e3a8a; }
.ag-message-item.warn .ag-message-text { color: #92400e; }
.ag-message-item.error .ag-message-text { color: #7f1d1d; }

.ag-message-meta {
    display: flex;
    gap: 16px;
    font-size: 12px;
    color: #6b7280;
    margin-top: 8px;
}

.ag-message-expiry {
    display: flex;
    align-items: center;
    gap: 4px;
}

.ag-message-expiry.expired {
    color: #dc2626;
}

.ag-message-expiry.expiring {
    color: #f59e0b;
}

.ag-message-actions {
    flex-shrink: 0;
    display: flex;
    gap: 8px;
}

.ag-message-btn {
    background: none;
    border: none;
    color: #6b7280;
    cursor: pointer;
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 12px;
    transition: all 0.2s ease;
}

.ag-message-btn:hover {
    background-color: rgba(0,0,0,0.05);
    color: #374151;
}

.ag-empty-state {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 60px 20px;
    color: #9ca3af;
    text-align: center;
}

.ag-empty-state i {
    font-size: 48px;
    margin-bottom: 16px;
    opacity: 0.5;
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const container = document.getElementById('messagesContainer');

    fetch('/api/v1/temporary-messages', {
        method: 'GET',
        headers: {
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(res => res.json())
    .then(data => {
        if (!data.messages || data.messages.length === 0) {
            container.innerHTML = `
                <div class="ag-empty-state">
                    <i class="fas fa-inbox"></i>
                    <p>No messages</p>
                </div>
            `;
            return;
        }

        container.innerHTML = '';
        data.messages.forEach(msg => {
            const messageEl = createMessageElement(msg);
            container.appendChild(messageEl);
        });
    })
    .catch(err => {
        console.error('Failed to load messages:', err);
        container.innerHTML = `
            <div class="ag-empty-state">
                <i class="fas fa-exclamation-circle"></i>
                <p>Failed to load messages</p>
            </div>
        `;
    });

    function createMessageElement(msg) {
        const wrapper = document.createElement('div');
        wrapper.className = `ag-message-item ${msg.severity || 'info'}`;

        const iconMap = {
            'error': 'fa-circle-exclamation',
            'warn': 'fa-triangle-exclamation',
            'info': 'fa-circle-info'
        };

        const icon = iconMap[msg.severity] || 'fa-circle-info';
        const expiresAt = new Date(msg.expires_at);
        const now = new Date();
        const minutesLeft = Math.round((expiresAt - now) / 1000 / 60);
        const hoursLeft = Math.round(minutesLeft / 60);
        const daysLeft = Math.round(hoursLeft / 24);

        let expiryText = 'Expires soon';
        let expiryClass = 'expired';
        if (minutesLeft > 0) {
            if (daysLeft > 0) {
                expiryText = `Expires in ${daysLeft} day${daysLeft !== 1 ? 's' : ''}`;
                expiryClass = 'expiring';
            } else if (hoursLeft > 0) {
                expiryText = `Expires in ${hoursLeft} hour${hoursLeft !== 1 ? 's' : ''}`;
                expiryClass = 'expiring';
            } else {
                expiryText = `Expires in ${minutesLeft} minute${minutesLeft !== 1 ? 's' : ''}`;
                expiryClass = 'expiring';
            }
        }

        wrapper.innerHTML = `
            <span class="ag-message-icon"><i class="fas ${icon}"></i></span>
            <div class="ag-message-content">
                <div class="ag-message-title">${escapeHtml(msg.credential_type === 'service_account' ? 'Service Account Key' : 'API Key')}</div>
                <div class="ag-message-text">${escapeHtml(msg.message)}</div>
                <div class="ag-message-meta">
                    <div class="ag-message-expiry ${expiryClass}">
                        <i class="fas fa-clock"></i>
                        <span>${expiryText}</span>
                    </div>
                </div>
            </div>
            <div class="ag-message-actions">
                <button type="button" class="ag-message-btn" onclick="markMessageRead(${msg.id}, this)" title="Dismiss">
                    <i class="fas fa-check"></i>
                </button>
            </div>
        `;

        return wrapper;
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    window.markMessageRead = function(messageId, btn) {
        fetch(`/api/v1/temporary-messages/${messageId}/read`, {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
            }
        })
        .then(() => {
            btn.closest('.ag-message-item').fadeOut(300, function() {
                this.remove();
                if (document.querySelectorAll('.ag-message-item').length === 0) {
                    document.getElementById('messagesContainer').innerHTML = `
                        <div class="ag-empty-state">
                            <i class="fas fa-inbox"></i>
                            <p>No messages</p>
                        </div>
                    `;
                }
            });
        })
        .catch(err => console.error('Failed to mark message read:', err));
    };

    // Add fadeOut to Element prototype if not exists
    if (!Element.prototype.fadeOut) {
        Element.prototype.fadeOut = function(duration, callback) {
            let opacity = 1;
            const timer = setInterval(() => {
                opacity -= 1 / (duration / 50);
                this.style.opacity = opacity;
                if (opacity <= 0) {
                    clearInterval(timer);
                    this.style.display = 'none';
                    if (callback) callback.call(this);
                }
            }, 50);
        };
    }
});
</script>
@endsection
