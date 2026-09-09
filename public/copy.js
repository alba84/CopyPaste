document.addEventListener('click', async (event) => {
    const button = event.target.closest('[data-copy]');
    if (!button) return;
    const source = document.querySelector(button.dataset.copy);
    if (!source) return;
    await navigator.clipboard.writeText('value' in source ? source.value : source.textContent);
    const oldLabel = button.textContent;
    button.textContent = 'Скопировано';
    setTimeout(() => { button.textContent = oldLabel; }, 1500);
});

const characterInput = document.querySelector('[data-character-input]');
const characterCount = document.querySelector('[data-character-count]');
if (characterInput && characterCount) {
    const updateCharacterCount = () => {
        characterCount.textContent = `${characterInput.value.length.toLocaleString('ru-RU')} / 100 000 символов`;
    };
    characterInput.addEventListener('input', updateCharacterCount);
    updateCharacterCount();
}
