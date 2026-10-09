// Lock screen and idle lock (FR-SEC-003, FR-UI-008).
// The SERVER decides whether the session is locked; this script only shows the
// overlay, reports whether the person was active, and posts the password.
// The page underneath is never reloaded, so unsaved work stays in place.

const body = document.body

if (body && body.hasAttribute('data-session-urls')) {
    const HEARTBEAT_MS = 30000
    const urls = {
        heartbeat: body.dataset.heartbeatUrl,
        lock: body.dataset.lockUrl,
        unlock: body.dataset.unlockUrl,
        login: body.dataset.loginUrl,
    }
    const overlay = document.querySelector('[data-lock-overlay]')
    const shell = document.querySelector('[data-app-shell]')
    const form = document.querySelector('[data-unlock-form]')
    const errorBox = document.querySelector('[data-unlock-error]')
    const reloadOnUnlock = body.hasAttribute('data-reload-on-unlock')
    let active = false
    let locked = body.hasAttribute('data-start-locked')

    const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content ?? ''

    const post = (url, data = {}) =>
        fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': csrf(),
            },
            body: JSON.stringify(data),
        })

    const showError = (message) => {
        if (!errorBox) return
        errorBox.textContent = message
        errorBox.classList.toggle('hidden', message === '')
    }

    const showLock = () => {
        locked = true
        showError('')
        if (form) form.reset()
        if (overlay) overlay.hidden = false
        // Keyboard and screen readers must not reach the page behind the overlay.
        if (shell) shell.setAttribute('inert', '')
        document.getElementById('unlock-password')?.focus()
    }

    const hideLock = () => {
        locked = false
        if (overlay) overlay.hidden = true
        if (shell) shell.removeAttribute('inert')
    }

    const apply = (state) => {
        if (state.locked && !locked) showLock()
    }

    const heartbeat = async () => {
        try {
            const response = await post(urls.heartbeat, { active })
            active = false
            if (response.status === 401) {
                window.location.href = urls.login
                return
            }
            if (response.ok) apply(await response.json())
        } catch (e) {
            // Server unreachable: the shell shows its own offline page. Try again later.
        }
    }

    for (const type of ['mousemove', 'keydown', 'pointerdown', 'scroll', 'touchstart']) {
        document.addEventListener(type, () => { active = true }, { passive: true })
    }

    document.querySelector('[data-lock-now]')?.addEventListener('click', async () => {
        const response = await post(urls.lock)
        if (response.ok) showLock()
    })

    form?.addEventListener('submit', async (event) => {
        event.preventDefault()
        const button = form.querySelector('button[type="submit"]')
        const password = new FormData(form).get('password')
        showError('')
        if (button) button.disabled = true // no double submit
        try {
            const response = await post(urls.unlock, { password })
            const json = await response.json().catch(() => ({}))
            if (response.ok && json.locked === false) {
                form.reset()
                active = true
                if (reloadOnUnlock) {
                    window.location.reload()
                } else {
                    hideLock()
                }
            } else if (response.status === 401) {
                window.location.href = urls.login
            } else {
                form.reset()
                showError(json.message || 'Could not unlock. Try again.')
            }
        } finally {
            if (button) button.disabled = false
        }
    })

    // A Livewire call made while locked gets 423. Show the overlay instead of
    // Livewire's error dialog. The call itself is not replayed: press the button again.
    document.addEventListener('livewire:init', () => {
        window.Livewire.interceptRequest(({ onError }) => {
            onError(({ response, preventDefault }) => {
                if (response.status === 423) {
                    preventDefault()
                    showLock()
                } else if (response.status === 401) {
                    preventDefault()
                    window.location.href = urls.login
                }
            })
        })
    })

    if (locked) showLock()
    setInterval(heartbeat, HEARTBEAT_MS)
}
