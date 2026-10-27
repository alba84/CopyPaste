(() => {
    'use strict';

    if (typeof window.PointerEvent !== 'function') {
        return;
    }

    const SHOW_LABEL = 'Показать секретный ключ';
    const HIDE_LABEL = 'Скрыть секретный ключ';

    const buttons = document.querySelectorAll('[data-secret-key-visibility]');

    buttons.forEach((button) => {
        const inputId = button.getAttribute('aria-controls');
        const input = inputId ? document.getElementById(inputId) : null;

        if (!input || input.type !== 'password') {
            return;
        }

        let activePointerId = null;

        const setVisible = (visible) => {
            if (visible === (button.getAttribute('aria-pressed') === 'true')) {
                return;
            }

            let selectionStart = null;
            let selectionEnd = null;
            let selectionDirection = null;

            if (document.activeElement === input) {
                try {
                    selectionStart = input.selectionStart;
                    selectionEnd = input.selectionEnd;
                    selectionDirection = input.selectionDirection;
                } catch (error) {
                    selectionStart = null;
                }
            }

            input.type = visible ? 'text' : 'password';
            button.setAttribute('aria-pressed', visible ? 'true' : 'false');
            button.setAttribute('aria-label', visible ? HIDE_LABEL : SHOW_LABEL);

            if (typeof selectionStart === 'number' && typeof selectionEnd === 'number') {
                try {
                    if (selectionDirection) {
                        input.setSelectionRange(selectionStart, selectionEnd, selectionDirection);
                    } else {
                        input.setSelectionRange(selectionStart, selectionEnd);
                    }
                } catch (error) {
                    // Selection is best-effort; browsers may reject it for a given input state.
                }
            }
        };

        const hide = () => {
            activePointerId = null;
            setVisible(false);
        };

        const isInsideButton = (event) => {
            const rect = button.getBoundingClientRect();

            return event.clientX >= rect.left
                && event.clientX <= rect.right
                && event.clientY >= rect.top
                && event.clientY <= rect.bottom;
        };

        button.addEventListener('pointerdown', (event) => {
            if (!event.isPrimary || event.button !== 0) {
                return;
            }

            event.preventDefault();
            activePointerId = event.pointerId;
            setVisible(true);
        });

        button.addEventListener('pointermove', (event) => {
            if (activePointerId === null || event.pointerId !== activePointerId) {
                return;
            }

            if (!isInsideButton(event)) {
                hide();
            }
        });

        button.addEventListener('pointerup', (event) => {
            if (activePointerId !== null && event.pointerId !== activePointerId) {
                return;
            }

            hide();
        });

        button.addEventListener('pointercancel', () => hide());
        button.addEventListener('pointerleave', () => hide());
        button.addEventListener('lostpointercapture', () => hide());

        button.addEventListener('contextmenu', (event) => {
            if (activePointerId !== null || button.getAttribute('aria-pressed') === 'true') {
                event.preventDefault();
            }
        });

        button.addEventListener('click', (event) => {
            if (event.detail !== 0) {
                return;
            }

            setVisible(button.getAttribute('aria-pressed') !== 'true');
        });

        button.addEventListener('blur', () => hide());

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                hide();
            }
        });

        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'hidden') {
                hide();
            }
        });

        window.addEventListener('blur', () => hide());

        button.hidden = false;
    });
})();
