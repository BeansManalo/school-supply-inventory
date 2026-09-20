// Live table filter: <input data-filter="tableId"> hides rows that don't match what's typed.
document.querySelectorAll('[data-filter]').forEach(input => {
    input.addEventListener('input', () => {
        const query = input.value.toLowerCase();
        document.querySelectorAll(`#${input.dataset.filter} tbody tr`).forEach(row => {
            row.hidden = !row.textContent.toLowerCase().includes(query);
        });
    });
});

// Ask before submitting any <form data-confirm="message">.
document.querySelectorAll('form[data-confirm]').forEach(form => {
    form.addEventListener('submit', event => {
        if (!confirm(form.dataset.confirm)) event.preventDefault();
    });
});
