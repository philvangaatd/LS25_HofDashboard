function showToast(msg, type) {
    const container = document.getElementById('toastContainer');
    const el = document.createElement('div');
    el.className = 'toast ' + type;
    el.textContent = msg;
    container.appendChild(el);
    setTimeout(() => {
        el.classList.add('fading');
        setTimeout(() => el.remove(), 300);
    }, 3200);
}

function setStatus(msg, cls) {
    const el = document.getElementById('status');
    el.textContent = msg;
    el.className = 'status ' + cls;
}

function escapeHtml(str) {
    return String(str).replace(/[&<>"']/g, c => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
    }[c]));
}
function escapeAttr(str) {
    return escapeHtml(str).replace(/`/g, '&#96;');
}

// -----------------------------------------------------------------
// Overflow-Menüs ("⋯ Mehr") für überfüllte Toolbars
// -----------------------------------------------------------------
function closeAllMoreMenus() {
    document.querySelectorAll('.more-menu').forEach(m => { m.style.display = 'none'; });
}

function toggleMoreMenu(id) {
    const menu = document.getElementById(id);
    const willOpen = menu.style.display === 'none';
    closeAllMoreMenus();
    if (willOpen) menu.style.display = 'flex';
}

document.addEventListener('click', (ev) => {
    if (!ev.target.closest('.toolbar-more')) closeAllMoreMenus();
});

window.addEventListener('beforeunload', (e) => {
    if (isDirty() || (typeof isCourseDirty === 'function' && isCourseDirty())) {
        e.preventDefault();
        e.returnValue = '';
    }
});

init();