try {
    if (localStorage.getItem('theme') === 'dark') {
        document.documentElement.setAttribute('data-theme', 'dark');
    }
} catch (error) {}

document.addEventListener('DOMContentLoaded', () => {
    let $button = document.getElementById('themeToggle');
    $button.addEventListener('click', () => {
        let theme = document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
        document.documentElement.setAttribute('data-theme', theme);
        document.dispatchEvent(new Event('themechange'));
        try {
            localStorage.setItem('theme', theme);
        } catch (error) {}
    });
});
