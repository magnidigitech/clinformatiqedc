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
});
