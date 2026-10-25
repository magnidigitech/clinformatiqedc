document.addEventListener('DOMContentLoaded', () => {
    // 1. Mobile Sidebar Logic (Existing)
    const createMobileToggle = () => {
        const topNav = document.querySelector('.top-nav');
        if (!topNav) return;
        if (document.querySelector('.mobile-menu-btn')) return;

        const toggleBtn = document.createElement('button');
        toggleBtn.innerHTML = '<span class="material-icons-round">menu</span>';
        toggleBtn.className = 'mobile-menu-btn';
        toggleBtn.style.cssText = `
            background: transparent; border: none; cursor: pointer; padding: 0.5rem; 
            margin-right: 0.5rem; display: none; align-items: center; color: var(--text-main);
        `;
        toggleBtn.classList.add('d-md-none');
        topNav.insertBefore(toggleBtn, topNav.firstChild);

        const sidebar = document.querySelector('.sidebar');
        const overlay = document.createElement('div');
        overlay.className = 'sidebar-overlay';
        document.body.appendChild(overlay);

        toggleBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            sidebar.classList.toggle('mobile-open');
            overlay.classList.toggle('active');
        });

        overlay.addEventListener('click', () => {
            sidebar.classList.remove('mobile-open');
            overlay.classList.remove('active');
        });
    };
    createMobileToggle();

    // 2. Tab Switching Logic
    const tabs = document.querySelectorAll('.tab-link');
    tabs.forEach(tab => {
        tab.addEventListener('click', () => {
            // Deactivate all
            document.querySelectorAll('.tab-link').forEach(t => t.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));

            // Activate current
            tab.classList.add('active');
            const targetId = tab.getAttribute('data-tab');
            document.getElementById(targetId).classList.add('active');
        });
    });

    // 3. Initialize Max Attribute on Date Inputs
    if (typeof initAllDateInputsMax === 'function') {
        initAllDateInputsMax();
    }
});

// =========================================================================
// GLOBAL TOAST NOTIFICATION HELPER
// =========================================================================
if (typeof window.showToast !== 'function') {
    window.showToast = function (message, type = 'success', duration = 3500) {
        let container = document.getElementById('toastContainer');
        if (!container) {
            container = document.createElement('div');
            container.id = 'toastContainer';
            container.style.cssText = 'position: fixed; bottom: 20px; right: 20px; z-index: 99999; display: flex; flex-direction: column; gap: 10px; pointer-events: none;';
            document.body.appendChild(container);
        }

        const toast = document.createElement('div');
        toast.style.cssText = `
            pointer-events: auto;
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px 18px;
            background: #0f172a;
            color: #ffffff;
            border-radius: 10px;
            box-shadow: 0 10px 15px -3px rgba(0,0,0,0.2), 0 4px 6px -2px rgba(0,0,0,0.1);
            font-size: 0.875rem;
            font-weight: 500;
            opacity: 0;
            transform: translateY(10px);
            transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1);
            max-width: 380px;
        `;

        let icon = 'info';
        let iconColor = '#3b82f6';
        if (type === 'success') { icon = 'check_circle'; iconColor = '#10b981'; }
        else if (type === 'error') { icon = 'error'; iconColor = '#ef4444'; }
        else if (type === 'warning') { icon = 'warning'; iconColor = '#f59e0b'; }

        function escapeToastHtml(text) {
            return String(text).replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
        }

        toast.innerHTML = `
            <span class="material-icons-round" style="color: ${iconColor}; font-size: 20px;">${icon}</span>
            <span style="flex: 1; line-height: 1.4;">${escapeToastHtml(message)}</span>
        `;

        container.appendChild(toast);

        requestAnimationFrame(() => {
            toast.style.opacity = '1';
            toast.style.transform = 'translateY(0)';
        });

        setTimeout(() => {
            toast.style.opacity = '0';
            toast.style.transform = 'translateY(10px)';
            setTimeout(() => toast.remove(), 300);
        }, duration);
    };
}

// =========================================================================
// INDIA STANDARD TIME (IST) DATE RESTRICTION & VALIDATION
// =========================================================================
function getTodayIST() {
    try {
        return new Intl.DateTimeFormat('en-CA', {
            timeZone: 'Asia/Kolkata',
            year: 'numeric',
            month: '2-digit',
            day: '2-digit'
        }).format(new Date());
    } catch (e) {
        const now = new Date();
        const istOffset = 5.5 * 60 * 60 * 1000;
        const istDate = new Date(now.getTime() + istOffset);
        return istDate.toISOString().slice(0, 10);
    }
}

function enforceNoFutureDate(input) {
    if (!input || (input.type !== 'date' && input.type !== 'datetime-local')) return false;
    if (input.hasAttribute('data-allow-future')) return false;

    const today = getTodayIST();
    if (input.type === 'date') {
        input.setAttribute('max', today);
    } else if (input.type === 'datetime-local') {
        input.setAttribute('max', today + 'T23:59:59');
    }

    if (input.value) {
        const valDate = input.value.slice(0, 10);
        if (valDate > today) {
            input.value = '';
            const msg = 'Future Date has been selected, Please Select the Correct Date';
            if (typeof window.showToast === 'function') {
                window.showToast(msg, 'warning');
            } else if (typeof showToast === 'function') {
                showToast(msg, 'warning');
            } else {
                alert(msg);
            }
            return true;
        }
    }
    return false;
}

// Intercept date input changes and inputs via capture phase on document
document.addEventListener('change', function (e) {
    if (e.target && (e.target.type === 'date' || e.target.type === 'datetime-local')) {
        enforceNoFutureDate(e.target);
    }
}, true);

document.addEventListener('input', function (e) {
    if (e.target && (e.target.type === 'date' || e.target.type === 'datetime-local') && e.target.value && e.target.value.length >= 10) {
        enforceNoFutureDate(e.target);
    }
}, true);

document.addEventListener('focusin', function (e) {
    if (e.target && (e.target.type === 'date' || e.target.type === 'datetime-local')) {
        enforceNoFutureDate(e.target);
    }
}, true);

function initAllDateInputsMax() {
    const today = getTodayIST();
    document.querySelectorAll('input[type="date"]').forEach(inp => {
        if (!inp.hasAttribute('data-allow-future')) {
            inp.setAttribute('max', today);
        }
    });
    document.querySelectorAll('input[type="datetime-local"]').forEach(inp => {
        if (!inp.hasAttribute('data-allow-future')) {
            inp.setAttribute('max', today + 'T23:59:59');
        }
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initAllDateInputsMax);
} else {
    initAllDateInputsMax();
}
